<?php

declare(strict_types=1);

namespace App\Kafka;

use App\Processing\ProcessingState;
use App\Processing\ProcessingStateRepository;
use App\Processing\ProcessingStatus;
use RuntimeException;

final readonly class RawOneCResponseHandler
{
    public function __construct(private ProcessingStateRepository $states)
    {
    }

    public function handle(string $payload, string $correlationPath): ProcessingState
    {
        // Пустой path безопасно запрещает угадывание неизвестного контракта 1С.
        if ('' === trim($correlationPath)) {
            throw new RuntimeException('KAFKA_RESPONSE_CORRELATION_PATH не настроен.');
        }
        $value = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        foreach (explode('.', $correlationPath) as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : null;
        }
        $correlationId = is_string($value) && '' !== $value ? $value : null;

        // Несвязанный ответ не сохраняем: consumer не подтвердит его offset и не потеряет сообщение.
        $state = null === $correlationId ? null : $this->states->findByCorrelationId($correlationId);
        if (null === $state) {
            throw new RuntimeException('Ответ 1С невозможно связать с исходной операцией.');
        }

        // До появления response schema сохраняем payload без интерпретации результата 1С.
        $received = new ProcessingState($state->jiraIssueId, $state->jiraIssueKey, $state->correlationId, $state->payloadHash, ProcessingStatus::ReceivedRaw, $state->createdAt, $state->sentAt, $payload);
        $this->states->save($received);
        return $received;
    }
}
