<?php

declare(strict_types=1);

namespace App\Processing;

interface ProcessingStateRepository
{
    public function findByIssueKey(string $issueKey): ?ProcessingState;
    public function findByCorrelationId(string $correlationId): ?ProcessingState;
    public function save(ProcessingState $state): void;
}
