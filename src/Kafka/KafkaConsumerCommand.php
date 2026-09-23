<?php

declare(strict_types=1);

namespace App\Kafka;

use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('app:kafka:consume-results', 'Читать RAW-ответы 1С из Kafka')]
final class KafkaConsumerCommand extends Command
{
    public function __construct(private readonly RawOneCResponseHandler $responseHandler, private readonly string $brokers, private readonly string $securityProtocol, private readonly string $saslMechanism, private readonly string $username, private readonly string $password, private readonly string $topic, private readonly string $consumerGroup, private readonly string $correlationPath)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Отключаем auto commit: offset подтверждается только после сохранения RAW ответа.
        $conf = new Conf();
        $conf->set('bootstrap.servers', $this->brokers);
        $conf->set('group.id', $this->consumerGroup);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('security.protocol', $this->securityProtocol);
        if ('plaintext' !== strtolower($this->securityProtocol)) {
            $conf->set('sasl.mechanism', $this->saslMechanism);
            $conf->set('sasl.username', $this->username);
            $conf->set('sasl.password', $this->password);
        }
        $consumer = new KafkaConsumer($conf);
        $consumer->subscribe([$this->topic]);

        // Worker работает постоянно; supervisor или Docker отвечает за перезапуск процесса.
        while (true) {
            $message = $consumer->consume(1000);
            if (RD_KAFKA_RESP_ERR__TIMED_OUT === $message->err || RD_KAFKA_RESP_ERR__PARTITION_EOF === $message->err) {
                continue;
            }
            if (RD_KAFKA_RESP_ERR_NO_ERROR !== $message->err) {
                throw new \RuntimeException($message->errstr(), $message->err);
            }

            // До подтверждения response schema сохраняем сообщение целиком и не интерпретируем результат.
            $state = $this->responseHandler->handle($message->payload, $this->correlationPath);
            $consumer->commit($message);
            $output->writeln(sprintf('Получен RAW ответ для %s.', $state->jiraIssueKey));
        }
    }
}
