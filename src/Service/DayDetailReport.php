<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SchoolEvent;
use App\Entity\Teacher;

final readonly class DayDetailReport
{
    /**
     * @param list<BoardTodayTimeSlot> $timeSlots
     * @param list<BoardTodaySanction> $sanctionedStudents
     * @param list<Teacher>            $absentTeachers
     * @param list<SchoolEvent>        $events
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $timeSlots,
        public array $sanctionedStudents,
        public array $absentTeachers,
        public array $events,
        public ?string $nonWorkingDayLabel = null,
    ) {}
}
