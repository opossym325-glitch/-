<?php

declare(strict_types=1);

namespace App\Processing;

use DateTimeImmutable;

final readonly class ProcessingState
{
    public function __construct(
        public string $jiraIssueId,
        public string $jiraIssueKey,
        public string $correlationId,
        public string $payloadHash,
        public ProcessingStatus $status,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $sentAt = null,
        public ?string $rawOneCResponse = null,
        public ?string $error = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // Redis хранит сериализуемое представление, не просачиваясь в прикладную модель.
        return ['jiraIssueId' => $this->jiraIssueId, 'jiraIssueKey' => $this->jiraIssueKey, 'correlationId' => $this->correlationId, 'payloadHash' => $this->payloadHash, 'status' => $this->status->value, 'createdAt' => $this->createdAt->format(DATE_ATOM), 'sentAt' => $this->sentAt?->format(DATE_ATOM), 'rawOneCResponse' => $this->rawOneCResponse, 'error' => $this->error];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        // Восстанавливаем типизированное состояние из repository storage.
        return new self((string) $data['jiraIssueId'], (string) $data['jiraIssueKey'], (string) $data['correlationId'], (string) $data['payloadHash'], ProcessingStatus::from((string) $data['status']), new DateTimeImmutable((string) $data['createdAt']), isset($data['sentAt']) ? new DateTimeImmutable((string) $data['sentAt']) : null, isset($data['rawOneCResponse']) ? (string) $data['rawOneCResponse'] : null, isset($data['error']) ? (string) $data['error'] : null);
    }
}
