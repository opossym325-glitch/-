<?php

declare(strict_types=1);

namespace App\Tests;

use App\Jira\JiraClient;
use App\Jira\JiraIssue;
use App\Jira\JiraIssueMapper;
use App\Kafka\MessagePublisher;
use App\Kafka\RawOneCResponseHandler;
use App\MarketplaceAccount\CreateMarketplaceAccountMessageFactory;
use App\MarketplaceAccount\MarketplaceAccountProcessor;
use App\Processing\ProcessingState;
use App\Processing\ProcessingStateRepository;
use App\Processing\ProcessingStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Validation;

final class MarketplaceAccountProcessorTest extends TestCase
{
    // Этот mapping повторяет только реально используемую часть подтверждённого справочника MARAUT.
    private const MAPPING = [
        'marketplace' => 'customfield_13617',
        'operationScheme' => 'customfield_13618',
        'firmagName' => 'customfield_13619',
        'brand' => 'customfield_13562',
        'marketplaceAccountId' => 'customfield_13551',
        'legalEntityId' => 'customfield_13583',
        'legalEntityName' => 'customfield_13584',
        'werkId' => 'customfield_13563',
        'warehousesWithStock' => 'customfield_13549',
    ];

    public function testIssueIsMappedToBusinessModel(): void
    {
        // Fixture повторяет безопасную часть реального MARAUT-652 без секретов и персональных данных.
        $data = (new JiraIssueMapper(self::MAPPING))->map($this->validIssue());
        self::assertSame('demo_test', $data->marketplace);
        self::assertSame('DBS', $data->operationScheme);
        self::assertSame('demo_test', $data->firmagName);
        self::assertNull($data->brand);
        self::assertSame(['Склад 1', 'Склад 2'], $data->warehousesWithStock);
    }

    public function testNullAndOptionalFieldsDoNotBreakMapping(): void
    {
        // До получения контракта 1С отсутствие бизнес-полей не должно блокировать тестовый pipeline.
        $data = (new JiraIssueMapper(self::MAPPING))->map(new JiraIssue('1', 'MARAUT-1', [
            'customfield_13617' => null,
            'customfield_13618' => null,
            'customfield_13549' => [],
        ]));
        self::assertNull($data->marketplace);
        self::assertNull($data->operationScheme);
        self::assertNull($data->firmagName);
        self::assertSame([], $data->warehousesWithStock);
    }

    public function testScalarSingleOptionAndOptionListAreNormalized(): void
    {
        // Один тест фиксирует три реальные формы значений Jira API без универсального serializer.
        $data = (new JiraIssueMapper(self::MAPPING))->map(new JiraIssue('2', 'MARAUT-2', [
            'customfield_13617' => 'Ozon',
            'customfield_13618' => ['value' => 'FBS', 'id' => '1'],
            'customfield_13549' => [['value' => 'Москва'], ['value' => 'Казань']],
        ]));
        self::assertSame('Ozon', $data->marketplace);
        self::assertSame('FBS', $data->operationScheme);
        self::assertSame(['Москва', 'Казань'], $data->warehousesWithStock);
    }

    public function testAllUsedDtoFieldsAreMappedFromConfirmedIds(): void
    {
        // Проверяем оставшиеся поля DTO на их подтверждённых customfield IDs.
        $fields = [
            'customfield_13617' => 'Market', 'customfield_13618' => ['value' => 'DBS'],
            'customfield_13619' => 'Store', 'customfield_13562' => 'Brand',
            'customfield_13551' => 101, 'customfield_13583' => 202,
            'customfield_13584' => 'Legal', 'customfield_13563' => 303,
        ];
        $data = (new JiraIssueMapper(self::MAPPING))->map(new JiraIssue('3', 'MARAUT-3', $fields));
        self::assertSame(['Market', 'DBS', 'Store', 'Brand', '101', '202', 'Legal', '303'], [$data->marketplace, $data->operationScheme, $data->firmagName, $data->brand, $data->marketplaceAccountId, $data->legalEntityId, $data->legalEntityName, $data->werkId]);
    }

    public function testValidIssuePublishesExpectedPayloadAndWaitsForOneC(): void
    {
        // Валидная заявка должна сформировать подтверждённые бизнес-поля и перейти в ожидание.
        $publisher = new CapturingPublisher();
        $states = new InMemoryStateRepository();
        $comments = [];
        $this->processor($publisher, $states, $comments)->process($this->validIssue());

        self::assertCount(1, $publisher->messages);
        self::assertSame('MARAUT-652', $publisher->messages[0]['key']);
        $payload = json_decode($publisher->messages[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('demo_test', $payload['marketplace']);
        self::assertSame('demo_test', $payload['firmagName']);
        self::assertSame(ProcessingStatus::WaitingOneC, $states->findByIssueKey('MARAUT-652')?->status);
        self::assertNotEmpty($states->findByIssueKey('MARAUT-652')?->correlationId);
    }

    public function testSuccessfullyPublishedIssueIsIdempotent(): void
    {
        // Повторный polling того же Epic не должен порождать второй CREATE.
        $publisher = new CapturingPublisher();
        $states = new InMemoryStateRepository();
        $comments = [];
        $processor = $this->processor($publisher, $states, $comments);
        $processor->process($this->validIssue());
        $processor->process($this->validIssue());
        self::assertCount(1, $publisher->messages);
    }

    public function testEmptyOptionalFieldsStillPublishDraftPayload(): void
    {
        // Validation остаётся в pipeline, но без неподтверждённых business constraints не создаёт ложный отказ.
        $publisher = new CapturingPublisher();
        $states = new InMemoryStateRepository();
        $comments = [];
        $this->processor($publisher, $states, $comments)->process(new JiraIssue('4', 'MARAUT-4', []));
        self::assertCount(1, $publisher->messages);
        self::assertSame(ProcessingStatus::WaitingOneC, $states->findByIssueKey('MARAUT-4')?->status);
        self::assertSame([], $comments);
    }

    public function testRawResponseIsSavedByCorrelationId(): void
    {
        // Подтверждённый correlation path связывает RAW Kafka response с исходной операцией.
        $states = new InMemoryStateRepository();
        $state = new ProcessingState('42', 'MARAUT-42', 'correlation-42', 'hash', ProcessingStatus::WaitingOneC, new \DateTimeImmutable());
        $states->save($state);
        $payload = '{"meta":{"requestId":"correlation-42"},"unknown":"kept"}';

        $received = (new RawOneCResponseHandler($states))->handle($payload, 'meta.requestId');
        self::assertSame(ProcessingStatus::ReceivedRaw, $received->status);
        self::assertSame($payload, $received->rawOneCResponse);
    }

    public function testUnknownResponseDoesNotOverwriteState(): void
    {
        // Ошибка связи выбрасывается до save, поэтому Kafka worker не сможет commit offset.
        $states = new InMemoryStateRepository();
        $this->expectException(\RuntimeException::class);
        (new RawOneCResponseHandler($states))->handle('{"requestId":"unknown"}', 'requestId');
    }

    public function testJiraTechnicalErrorIsNotBusinessValidation(): void
    {
        // HTTP-сбой должен остаться техническим исключением до создания processing state.
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 503]));
        $jira = new JiraClient($http, 'https://jira.test', 'MARAUT', 'Эпика', 'Ready', 'bearer', 'token', '', '', 50);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');
        $jira->findReadyIssues();
    }

    public function testJiraUsesRealEpicTypeAndPreservesSystemFields(): void
    {
        // Jira query должен использовать подтверждённое русское имя типа и запрашивать полный набор fields.
        $fixture = file_get_contents(__DIR__.'/Fixtures/maraut-652.json');
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($fixture): MockResponse {
            self::assertSame('GET', $method);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertStringContainsString('project = "MARAUT"', $query['jql']);
            self::assertStringContainsString('issuetype = "Эпика"', $query['jql']);
            self::assertSame('*all', $query['fields']);
            return new MockResponse('{"issues":['.$fixture.']}', ['http_code' => 200, 'response_headers' => ['content-type: application/json']]);
        });
        $jira = new JiraClient($http, 'https://jira.test', 'MARAUT', 'Эпика', 'Тестовый статус', 'bearer', 'token', '', '', 50);

        $issue = $jira->findReadyIssues()[0];
        self::assertSame('MARAUT-652', $issue->key);
        self::assertSame('demo_test', $issue->summary);
        self::assertSame('10002', $issue->issueTypeId);
        self::assertSame('Эпика', $issue->issueType);
        self::assertSame('MARAUT', $issue->projectKey);
        self::assertSame('Создание ЛК МП', $issue->projectName);
        self::assertSame('Тестовый статус', $issue->status);
        self::assertNotNull($issue->created);
        self::assertNotNull($issue->updated);
    }

    /** @param list<string> $comments */
    private function processor(CapturingPublisher $publisher, InMemoryStateRepository $states, array &$comments): MarketplaceAccountProcessor
    {
        // Mock HTTP используется только на внешней Jira-границе; внутренняя логика остаётся реальной.
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$comments): MockResponse {
            $comments[] = (string) ($options['json']['body'] ?? '');
            return new MockResponse('', ['http_code' => 201]);
        });
        $jira = new JiraClient($http, 'https://jira.test', 'MARAUT', 'Эпика', 'Ready', 'bearer', 'token', '', '', 50);
        return new MarketplaceAccountProcessor(new JiraIssueMapper(self::MAPPING), Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), new CreateMarketplaceAccountMessageFactory(), $publisher, $states, $jira, new NullLogger(), true);
    }

    private function validIssue(): JiraIssue
    {
        // Загружаем реалистичную fixture MARAUT-652, очищенную от секретов и персональных данных.
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/maraut-652.json'), true, 512, JSON_THROW_ON_ERROR);
        return new JiraIssue($fixture['id'], $fixture['key'], $fixture['fields']);
    }
}

final class CapturingPublisher implements MessagePublisher
{
    /** @var list<array{key: string, payload: string}> */
    public array $messages = [];

    public function publish(string $key, string $payload): void
    {
        $this->messages[] = ['key' => $key, 'payload' => $payload];
    }
}

final class InMemoryStateRepository implements ProcessingStateRepository
{
    /** @var array<string, ProcessingState> */
    private array $byIssue = [];
    /** @var array<string, ProcessingState> */
    private array $byCorrelation = [];

    public function findByIssueKey(string $issueKey): ?ProcessingState
    {
        return $this->byIssue[$issueKey] ?? null;
    }

    public function findByCorrelationId(string $correlationId): ?ProcessingState
    {
        return $this->byCorrelation[$correlationId] ?? null;
    }

    public function save(ProcessingState $state): void
    {
        $this->byIssue[$state->jiraIssueKey] = $state;
        $this->byCorrelation[$state->correlationId] = $state;
    }
}
