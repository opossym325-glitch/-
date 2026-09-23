<?php

declare(strict_types=1);

namespace App\MarketplaceAccount;

use App\Jira\JiraClient;
use App\Jira\JiraIssue;
use App\Jira\JiraIssueMapper;
use App\Kafka\MessagePublisher;
use App\Processing\ProcessingState;
use App\Processing\ProcessingStateRepository;
use App\Processing\ProcessingStatus;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class MarketplaceAccountProcessor
{
    public function __construct(private JiraIssueMapper $mapper, private ValidatorInterface $validator, private CreateMarketplaceAccountMessageFactory $factory, private MessagePublisher $publisher, private ProcessingStateRepository $states, private JiraClient $jira, private LoggerInterface $logger, private bool $processingEnabled)
    {
    }

    public function process(JiraIssue $issue): void
    {
        // Первый production-запуск требует явного включения после проверки Jira-выборки.
        if (!$this->processingEnabled) {
            $this->logger->notice('Обработка Jira отключена защитным флагом.', ['jiraIssueKey' => $issue->key, 'operation' => 'jira_poll']);
            return;
        }

        // Один Epic создаёт только один успешно опубликованный CREATE; UPDATE пока вне scope.
        $existing = $this->states->findByIssueKey($issue->key);
        if (null !== $existing && in_array($existing->status, [ProcessingStatus::Publishing, ProcessingStatus::WaitingOneC, ProcessingStatus::ReceivedRaw], true)) {
            return;
        }

        // Каждая новая попытка после исправления VALIDATION_FAILED получает собственный correlation ID.
        $data = $this->mapper->map($issue);
        $correlationId = Uuid::v7()->toRfc4122();
        $violations = $this->validator->validate($data);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = (string) $violation->getMessage();
            }
            $errorText = 'Не заполнены обязательные поля: '.implode(', ', array_unique($errors));
            $state = new ProcessingState($issue->id, $issue->key, $correlationId, '', ProcessingStatus::ValidationFailed, new DateTimeImmutable(), error: $errorText);
            $this->states->save($state);

            // Пользователь получает только понятный список полей без технических подробностей.
            $this->jira->addComment($issue->key, "Не удалось передать заявку в 1С.\n\nНе заполнены обязательные поля:\n- ".implode("\n- ", array_unique($errors)));
            $this->logger->warning('Заявка не прошла бизнес-валидацию.', ['jiraIssueKey' => $issue->key, 'correlationId' => $correlationId, 'status' => ProcessingStatus::ValidationFailed->value]);
            return;
        }

        // Сериализуем нормализованное сообщение детерминированно для payload hash и Kafka.
        $message = $this->factory->create($data, $correlationId);
        $payload = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payload);
        $createdAt = new DateTimeImmutable();
        $this->states->save(new ProcessingState($issue->id, $issue->key, $correlationId, $hash, ProcessingStatus::Publishing, $createdAt));

        // Jira key выбран стабильным Kafka key; корпоративное правило нужно подтвердить до production.
        try {
            $this->publisher->publish($issue->key, $payload);
        } catch (\Throwable $error) {
            // Ошибка публикации сохраняется как техническая и пробрасывается для retry Messenger.
            $this->states->save(new ProcessingState($issue->id, $issue->key, $correlationId, $hash, ProcessingStatus::Failed, $createdAt, error: $error->getMessage()));
            $this->logger->error('Kafka publication failed.', ['jiraIssueKey' => $issue->key, 'correlationId' => $correlationId, 'status' => ProcessingStatus::Failed->value]);
            throw $error;
        }
        $this->states->save(new ProcessingState($issue->id, $issue->key, $correlationId, $hash, ProcessingStatus::WaitingOneC, $createdAt, new DateTimeImmutable()));
        $this->logger->info('Заявка опубликована в Kafka.', ['jiraIssueKey' => $issue->key, 'correlationId' => $correlationId, 'status' => ProcessingStatus::WaitingOneC->value]);
    }
}
