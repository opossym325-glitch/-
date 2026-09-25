<?php

declare(strict_types=1);

namespace App\Jira;

final readonly class JiraIssue
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        public string $id,
        public string $key,
        public array $fields,
        public ?string $summary = null,
        public ?string $status = null,
        public ?string $issueTypeId = null,
        public ?string $issueType = null,
        public ?string $projectKey = null,
        public ?string $projectName = null,
        public ?string $created = null,
        public ?string $updated = null,
    ) {
    }
}
