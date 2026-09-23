<?php

declare(strict_types=1);

namespace App\Processing;

use Redis;
use RuntimeException;

final class RedisProcessingStateRepository implements ProcessingStateRepository
{
    private const ISSUE_PREFIX = 'marketplace-account:issue:';
    private const CORRELATION_PREFIX = 'marketplace-account:correlation:';
    private Redis $redis;

    public function __construct(string $redisDsn)
    {
        // Подключение скрыто внутри repository и может быть заменено PostgreSQL-реализацией.
        $parts = parse_url($redisDsn);
        if (false === $parts || !isset($parts['host'])) {
            throw new RuntimeException('Некорректный REDIS_DSN.');
        }
        $this->redis = new Redis();
        $this->redis->connect($parts['host'], $parts['port'] ?? 6379);
        if (isset($parts['path']) && '/' !== $parts['path']) {
            $this->redis->select((int) ltrim($parts['path'], '/'));
        }
    }

    public function findByIssueKey(string $issueKey): ?ProcessingState
    {
        return $this->read(self::ISSUE_PREFIX.$issueKey);
    }

    public function findByCorrelationId(string $correlationId): ?ProcessingState
    {
        return $this->read(self::CORRELATION_PREFIX.$correlationId);
    }

    public function save(ProcessingState $state): void
    {
        // Два индекса связывают Jira polling и асинхронный ответ 1С с одной операцией.
        $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->redis->multi()->set(self::ISSUE_PREFIX.$state->jiraIssueKey, $json)->set(self::CORRELATION_PREFIX.$state->correlationId, $json)->exec();
    }

    private function read(string $key): ?ProcessingState
    {
        // Отсутствующий ключ является штатным признаком новой операции.
        $json = $this->redis->get($key);
        return false === $json ? null : ProcessingState::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }
}
