<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Jira\JiraClient;
use App\MarketplaceAccount\MarketplaceAccountProcessor;
use App\Message\CheckJiraIssuesMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckJiraIssuesMessageHandler
{
    public function __construct(private JiraClient $jira, private MarketplaceAccountProcessor $processor)
    {
    }

    public function __invoke(CheckJiraIssuesMessage $message): void
    {
        // Handler отделяет планирование от обращения в Jira и обработки каждой заявки.
        foreach ($this->jira->findReadyIssues() as $issue) {
            $this->processor->process($issue);
        }
    }
}
