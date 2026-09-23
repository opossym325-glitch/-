<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\CheckJiraIssuesMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('app:jira:process', 'Поставить проверку готовых MARAUT Epic в очередь')]
final class ProcessJiraCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Ручной запуск использует ровно тот же Messenger pipeline, что и Scheduler.
        $this->bus->dispatch(new CheckJiraIssuesMessage());
        $output->writeln('Проверка Jira поставлена в очередь.');
        return self::SUCCESS;
    }
}
