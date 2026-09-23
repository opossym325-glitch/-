<?php

declare(strict_types=1);

namespace App\Jira;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class JiraClient
{
    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
        private string $projectKey,
        private string $issueType,
        private string $readyStatus,
        private string $authType,
        private string $token,
        private string $username,
        private string $password,
        private int $maxResults,
    ) {
    }

    /** @return list<JiraIssue> */
    public function findReadyIssues(): array
    {
        // Не выполняем широкий поиск, пока production-статус явно не настроен.
        if ('' === trim($this->readyStatus)) {
            throw new RuntimeException('JIRA_READY_STATUS не настроен.');
        }

        // Экранируем строковые значения JQL, чтобы настройки не меняли структуру запроса.
        $quote = static fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        $jql = sprintf('project = %s AND issuetype = %s AND status = %s ORDER BY created ASC', $quote($this->projectKey), $quote($this->issueType), $quote($this->readyStatus));

        // Используем корпоративно применявшийся Jira REST API v2 и не передаём секреты в логируемые URL.
        $response = $this->http->request('GET', rtrim($this->baseUrl, '/').'/rest/api/2/search', $this->options([
            'query' => ['jql' => $jql, 'maxResults' => $this->maxResults],
        ]));

        // Технические ответы Jira оставляем исключениями: processor не должен выдавать их за ошибки данных.
        $status = $response->getStatusCode();
        if (401 === $status || 403 === $status) {
            throw new RuntimeException('Jira отклонила авторизацию.');
        }
        if (200 !== $status) {
            throw new RuntimeException(sprintf('Jira search вернул HTTP %d.', $status));
        }

        $body = $response->toArray(false);
        if (!isset($body['issues']) || !is_array($body['issues'])) {
            throw new RuntimeException('Jira вернула некорректный ответ search.');
        }

        // Преобразуем транспортный JSON в небольшой Jira DTO, сохраняя mapping отдельно.
        return array_map(static function (array $issue): JiraIssue {
            if (!is_string($issue['id'] ?? null) || !is_string($issue['key'] ?? null) || !is_array($issue['fields'] ?? null)) {
                throw new RuntimeException('Jira issue не содержит id, key или fields.');
            }
            return new JiraIssue($issue['id'], $issue['key'], $issue['fields']);
        }, $body['issues']);
    }

    public function addComment(string $issueKey, string $text): void
    {
        // Пишем бизнес-понятный комментарий в исходный Epic через стандартный Jira endpoint.
        $response = $this->http->request('POST', rtrim($this->baseUrl, '/').'/rest/api/2/issue/'.rawurlencode($issueKey).'/comment', $this->options([
            'json' => ['body' => $text],
        ]));
        if (201 !== $response->getStatusCode()) {
            throw new RuntimeException(sprintf('Jira comment вернул HTTP %d.', $response->getStatusCode()));
        }
    }

    /** @param array<string, mixed> $options
     *  @return array<string, mixed>
     */
    private function options(array $options): array
    {
        // Передаём HttpClient только выбранный способ авторизации без null-параметров.
        if ('bearer' === $this->authType) {
            $options['auth_bearer'] = $this->token;
        } elseif ('basic' === $this->authType) {
            $options['auth_basic'] = [$this->username, $this->password];
        } else {
            throw new RuntimeException('Поддерживаются JIRA_AUTH_TYPE bearer и basic.');
        }
        return $options;
    }
}
