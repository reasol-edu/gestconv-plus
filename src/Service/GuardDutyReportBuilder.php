<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Teacher;
use App\Repository\TimeSlotRepository;

/**
 * Builds the "Profesorado de guardia" report: one row per distinct start/end
 * time combination of the week, with the guard teachers of each weekday
 * (Monday-Friday) in that time slot.
 */
class GuardDutyReportBuilder
{
    /** Monday(0) .. Friday(4), matching TimeSlotComponent::DAYS. */
    private const DAYS = [0, 1, 2, 3, 4];

    public function __construct(
        private readonly TimeSlotRepository $timeSlots,
    ) {}

    public function build(AcademicYear $year): GuardDutyReport
    {
        $slots = $this->timeSlots->findByAcademicYearOrderedWithGuards($year);

        /** @var array<string, array{startTime: \DateTimeImmutable, endTime: \DateTimeImmutable, names: array<string, true>, guardsByDay: array<int, list<Teacher>>}> $byTime */
        $byTime = [];

        foreach ($slots as $slot) {
            $day = $slot->getDayOfWeek();
            if (!in_array($day, self::DAYS, true)) {
                continue;
            }

            $timeKey = $slot->getStartTime()->format('H:i:s') . '-' . $slot->getEndTime()->format('H:i:s');

            $byTime[$timeKey] ??= [
                'startTime'   => $slot->getStartTime(),
                'endTime'     => $slot->getEndTime(),
                'names'       => [],
                'guardsByDay' => array_fill_keys(self::DAYS, []),
            ];

            $byTime[$timeKey]['names'][$slot->getName()] = true;

            foreach ($slot->getGuards() as $guard) {
                $byTime[$timeKey]['guardsByDay'][$day][] = $guard;
            }
        }

        uasort($byTime, static fn (array $a, array $b): int => $a['startTime'] <=> $b['startTime']);

        $rows = [];
        foreach ($byTime as $entry) {
            $rows[] = new GuardDutyRow(
                implode(' / ', array_keys($entry['names'])),
                $entry['startTime'],
                $entry['endTime'],
                $entry['guardsByDay'],
            );
        }

        return new GuardDutyReport($rows);
    }
}
