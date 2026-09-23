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
    private const MAPPING = ['marketplace' => 'cf_marketplace', 'legalEntity' => 'cf_legal', 'operationScheme' => 'cf_scheme', 'marketplaceAccountId' => 'cf_account', 'werks' => 'cf_werks'];

    public function testIssueIsMappedToBusinessModel(): void
    {
        // Проверяем границу Jira JSON -> внутренняя модель без Jira field IDs.
        $data = (new JiraIssueMapper(self::MAPPING))->map($this->validIssue());
        self::assertSame('Ozon', $data->marketplace);
        self::assertSame('ООО Пример', $data->legalEntity);
        self::assertSame('DBS', $data->operationScheme);
        self::assertSame('42', $data->marketplaceAccountId);
        self::assertSame('1000', $data->werks);
    }

    public function testValidationFailureDoesNotPublishAndWritesComment(): void
    {
        // Неполная заявка должна сохранить бизнес-ошибку и не попасть в Kafka.
        $publisher = new CapturingPublisher();
        $states = new InMemoryStateRepository();
        $comments = [];
        $processor = $this->processor($publisher, $states, $comments);
        $processor->process(new JiraIssue('1', 'MARAUT-1', []));

        self::assertSame([], $publisher->messages);
        self::assertSame(ProcessingStatus::ValidationFailed, $states->findByIssueKey('MARAUT-1')?->status);
        self::assertStringContainsString('Юридическое лицо', $comments[0]);
    }

    public function testValidIssuePublishesExpectedPayloadAndWaitsForOneC(): void
    {
        // Валидная заявка должна сформировать подтверждённые бизнес-поля и перейти в ожидание.
        $publisher = new CapturingPublisher();
        $states = new InMemoryStateRepository();
        $comments = [];
        $this->processor($publisher, $states, $comments)->process($this->validIssue());

        self::assertCount(1, $publisher->messages);
        self::assertSame('MARAUT-42', $publisher->messages[0]['key']);
        self::assertSame('Ozon', json_decode($publisher->messages[0]['payload'], true, 512, JSON_THROW_ON_ERROR)['marketplace']);
        self::assertSame(ProcessingStatus::WaitingOneC, $states->findByIssueKey('MARAUT-42')?->status);
        self::assertNotEmpty($states->findByIssueKey('MARAUT-42')?->correlationId);
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

    public function testValidationFailedIssueCanRetryAfterCorrection(): void
    {
        // VALIDATION_FAILED не закрывает Epic навсегда: исправленные поля запускают новую попытку.
        $publisher = new CapturingPublisher();
        $states = new InMemoryStateRepository();
        $comments = [];
        $processor = $this->processor($publisher, $states, $comments);
        $processor->process(new JiraIssue('42', 'MARAUT-42', []));
        $processor->process($this->validIssue());
        self::assertCount(1, $publisher->messages);
        self::assertSame(ProcessingStatus::WaitingOneC, $states->findByIssueKey('MARAUT-42')?->status);
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
        $jira = new JiraClient($http, 'https://jira.test', 'MARAUT', 'Epic', 'Ready', 'bearer', 'token', '', '', 50);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');
        $jira->findReadyIssues();
    }

    /** @param list<string> $comments */
    private function processor(CapturingPublisher $publisher, InMemoryStateRepository $states, array &$comments): MarketplaceAccountProcessor
    {
        // Mock HTTP используется только на внешней Jira-границе; внутренняя логика остаётся реальной.
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$comments): MockResponse {
            $comments[] = (string) ($options['json']['body'] ?? '');
            return new MockResponse('', ['http_code' => 201]);
        });
        $jira = new JiraClient($http, 'https://jira.test', 'MARAUT', 'Epic', 'Ready', 'bearer', 'token', '', '', 50);
        return new MarketplaceAccountProcessor(new JiraIssueMapper(self::MAPPING), Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), new CreateMarketplaceAccountMessageFactory(), $publisher, $states, $jira, new NullLogger(), true);
    }

    private function validIssue(): JiraIssue
    {
        // Тестовый JSON использует IDs только из локального mapping, а не выдаёт их за production IDs.
        return new JiraIssue('42', 'MARAUT-42', ['cf_marketplace' => ['value' => 'Ozon'], 'cf_legal' => 'ООО Пример', 'cf_scheme' => ['value' => 'DBS'], 'cf_account' => 42, 'cf_werks' => '1000']);
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
