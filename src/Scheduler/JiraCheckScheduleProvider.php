<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\CheckJiraIssuesMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

#[AsSchedule('jira')]
final readonly class JiraCheckScheduleProvider
{
    public function __construct(private string $interval)
    {
    }

    public function getSchedule(): Schedule
    {
        // Scheduler только ставит периодическую команду в Messenger и не содержит бизнес-логики.
        return (new Schedule())->add(RecurringMessage::trigger(new PeriodicalTrigger($this->interval), new CheckJiraIssuesMessage()));
    }
}
