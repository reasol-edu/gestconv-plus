<?php

declare(strict_types=1);

namespace App;

use App\Message\AutoPrescribeIncidentReportsMessage;
use App\Message\PurgeActivityLogMessage;
use App\Message\WarnUpcomingReportPrescriptionsMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->add(
                RecurringMessage::cron('0 3 * * 0', new PurgeActivityLogMessage()),
                RecurringMessage::cron('0 4 * * *', new AutoPrescribeIncidentReportsMessage()),
                RecurringMessage::cron('30 4 * * *', new WarnUpcomingReportPrescriptionsMessage()),
            )
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
        ;
    }
}
