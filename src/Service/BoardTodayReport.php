<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Teacher;

final readonly class BoardTodayReport
{
    /**
     * @param list<BoardTodayTimeSlot>  $timeSlots
     * @param list<Teacher>             $absentTeachers
     * @param list<BoardTodaySanction>  $sanctionedStudents
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $timeSlots,
        public array $absentTeachers,
        public array $sanctionedStudents,
    ) {}
}
