<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\TimeSlot;

final readonly class BoardTodayTimeSlot
{
    /**
     * @param list<Activity> $activities
     */
    public function __construct(
        public TimeSlot $timeSlot,
        public array $activities,
    ) {}
}
