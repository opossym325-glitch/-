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
        // Внутренняя модель содержит подтверждённые бизнес-названия и не знает о customfield IDs.
        return new MarketplaceAccountData(
            $this->scalar($issue, 'marketplace'),
            $this->scalar($issue, 'operationScheme'),
            $this->scalar($issue, 'firmagName'),
            $this->scalar($issue, 'brand'),
            $this->scalar($issue, 'marketplaceAccountId'),
            $this->scalar($issue, 'legalEntityId'),
            $this->scalar($issue, 'legalEntityName'),
            $this->scalar($issue, 'werkId'),
            $this->list($issue, 'warehousesWithStock'),
        );
    }

    private function scalar(JiraIssue $issue, string $businessName): ?string
    {
        // Scalar и single option нормализуем в одно строковое значение для DTO.
        $value = $this->extract($issue, $businessName);
        return is_string($value) ? $value : null;
    }

    /** @return list<string> */
    private function list(JiraIssue $issue, string $businessName): array
    {
        // Multiselect возвращаем списком; null означает пустой список, а одиночное значение сохраняем как один элемент.
        $value = $this->extract($issue, $businessName);
        if (null === $value) {
            return [];
        }
        return is_array($value) ? $value : [$value];
    }

    /** @return string|list<string>|null */
    private function extract(JiraIssue $issue, string $businessName): string|array|null
    {
        // Все Jira customfield IDs читаются только из централизованной конфигурации.
        $fieldId = trim($this->fieldMapping[$businessName] ?? '');
        if ('' === $fieldId) {
            return null;
        }
        $raw = $issue->fields[$fieldId] ?? null;

        // Простые Jira text/number fields приходят scalar-значениями.
        if (is_scalar($raw)) {
            $value = trim((string) $raw);
            return '' === $value ? null : $value;
        }
        if (!is_array($raw)) {
            return null;
        }

        // Одиночный option является ассоциативным объектом с человекочитаемым value.
        if (!array_is_list($raw)) {
            return $this->optionValue($raw);
        }

        // Multiselect/multicheckbox является списком option-объектов или scalar-значений.
        $values = [];
        foreach ($raw as $item) {
            $value = is_array($item) ? $this->optionValue($item) : (is_scalar($item) ? trim((string) $item) : null);
            if (null !== $value && '' !== $value) {
                $values[] = $value;
            }
        }
        return $values;
    }

    /** @param array<string, mixed> $option */
    private function optionValue(array $option): ?string
    {
        // Jira options обычно используют value; name/key поддержаны для подтверждённых системоподобных объектов.
        $value = $option['value'] ?? $option['name'] ?? $option['key'] ?? null;
        return is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }
}
