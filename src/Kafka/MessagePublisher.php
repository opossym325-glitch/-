<?php

declare(strict_types=1);

namespace App\Kafka;

interface MessagePublisher
{
    public function publish(string $key, string $payload): void;
}
