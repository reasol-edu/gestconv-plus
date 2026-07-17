<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Sanction;
use App\Repository\AbsenceRepository;
use App\Repository\ActivityRepository;
use App\Repository\SanctionRepository;
use App\Repository\TimeSlotRepository;

/**
 * Builds the "Hoy" board screen: every time slot of the given weekday with
 * its guard teachers and the activities registered for that day, plus the
 * list of teachers absent that day and the students sanctioned that day.
 */
class BoardTodayBuilder
{
    private const int SANCTION_LABEL_LIMIT = 70;

    public function __construct(
        private readonly TimeSlotRepository $timeSlots,
        private readonly ActivityRepository $activities,
        private readonly AbsenceRepository $absences,
        private readonly SanctionRepository $sanctions,
    ) {}

    public function build(AcademicYear $year, \DateTimeImmutable $date): BoardTodayReport
    {
        $dayOfWeek = ((int) $date->format('N')) - 1;

        $slots = $this->timeSlots->findByAcademicYearAndDay($year, $dayOfWeek);

        /** @var array<string, list<\App\Entity\Activity>> $activitiesBySlot */
        $activitiesBySlot = [];
        foreach ($this->activities->findByAcademicYearAndDate($year, $date) as $activity) {
            $activitiesBySlot[$activity->getTimeSlot()->getId()->toRfc4122()][] = $activity;
        }

        $timeSlots = [];
        foreach ($slots as $slot) {
            $timeSlots[] = new BoardTodayTimeSlot(
                $slot,
                $activitiesBySlot[$slot->getId()->toRfc4122()] ?? [],
            );
        }

        $sanctionedStudents = array_map(
            fn (Sanction $sanction): BoardTodaySanction => new BoardTodaySanction(
                $sanction->getStudent(),
                $sanction->getGroup(),
                $sanction->getCalendarLabel() ?? $this->truncate(trim(strip_tags($sanction->getDetails()))),
            ),
            $this->sanctions->findActiveOn($year, $date),
        );

        return new BoardTodayReport(
            $date,
            $timeSlots,
            $this->absences->findTeachersAbsentOn($year, $date),
            $sanctionedStudents,
        );
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::SANCTION_LABEL_LIMIT) {
            return $text;
        }

        return mb_substr($text, 0, self::SANCTION_LABEL_LIMIT - 1) . '…';
    }
}
