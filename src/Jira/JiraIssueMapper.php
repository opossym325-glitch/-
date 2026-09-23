<?php

declare(strict_types=1);

namespace App\Jira;

use App\MarketplaceAccount\MarketplaceAccountData;

final readonly class JiraIssueMapper
{
    /** @param array<string, string> $fieldMapping */
    public function __construct(private array $fieldMapping)
    {
    }

    public function map(JiraIssue $issue): MarketplaceAccountData
    {
        // Все Jira customfield IDs читаются только из централизованной конфигурации.
        $value = function (string $businessName) use ($issue): ?string {
            $fieldId = trim($this->fieldMapping[$businessName] ?? '');
            if ('' === $fieldId) {
                return null;
            }
            $raw = $issue->fields[$fieldId] ?? null;
            if (is_array($raw)) {
                $raw = $raw['value'] ?? $raw['name'] ?? $raw['key'] ?? null;
            }
            return is_scalar($raw) ? trim((string) $raw) : null;
        };

        // Внутренняя модель содержит только бизнес-названия и не знает о Jira IDs.
        return new MarketplaceAccountData($value('marketplace'), $value('legalEntity'), $value('operationScheme'), $value('marketplaceAccountId'), $value('werks'));
    }
}
