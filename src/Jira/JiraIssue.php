<?php

declare(strict_types=1);

namespace App\Jira;

final readonly class JiraIssue
{
    /** @param array<string, mixed> $fields */
    public function __construct(public string $id, public string $key, public array $fields)
    {
    }
}
