<?php

declare(strict_types=1);

namespace App\Kafka;

use RdKafka\Conf;
use RdKafka\Producer;
use RuntimeException;

final readonly class KafkaProducer implements MessagePublisher
{
    public function __construct(private string $brokers, private string $securityProtocol, private string $saslMechanism, private string $username, private string $password, private string $topic)
    {
    }

    public function publish(string $key, string $payload): void
    {
        // Используем librdkafka и подтверждение доставки, прежде чем считать CREATE отправленным.
        $conf = new Conf();
        $conf->set('bootstrap.servers', $this->brokers);
        $conf->set('security.protocol', $this->securityProtocol);
        if ('plaintext' !== strtolower($this->securityProtocol)) {
            $conf->set('sasl.mechanism', $this->saslMechanism);
            $conf->set('sasl.username', $this->username);
            $conf->set('sasl.password', $this->password);
        }
        $producer = new Producer($conf);
        $producer->newTopic($this->topic)->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);
        $producer->poll(0);
        if (RD_KAFKA_RESP_ERR_NO_ERROR !== $producer->flush(10000)) {
            throw new RuntimeException('Kafka не подтвердила публикацию за 10 секунд.');
        }
    }
}
