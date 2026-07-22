<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Repository\NonWorkingDayRepository;

final readonly class NonWorkingDayChecker
{
    public function __construct(
        private NonWorkingDayRepository $nonWorkingDays,
    ) {
    }

    public function isWeekend(\DateTimeImmutable $date): bool
    {
        return (int) $date->format('N') >= 6;
    }

    public function isNonWorkingDay(AcademicYear $year, \DateTimeImmutable $date): bool
    {
        return $this->isWeekend($date) || $this->nonWorkingDays->findByAcademicYearAndDate($year, $date) !== null;
    }

    public function descriptionFor(AcademicYear $year, \DateTimeImmutable $date): ?string
    {
        return $this->nonWorkingDays->findByAcademicYearAndDate($year, $date)?->getDescription();
    }

    public function countSchoolDays(AcademicYear $year, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $holidays = $this->holidaySet($year);

        $count  = 0;
        $cursor = $from;
        while ($cursor <= $to) {
            if (!$this->isWeekend($cursor) && !isset($holidays[$cursor->format('Y-m-d')])) {
                ++$count;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $count;
    }

    public function addSchoolDays(AcademicYear $year, \DateTimeImmutable $from, int $schoolDays): \DateTimeImmutable
    {
        $holidays = $this->holidaySet($year);

        $remaining = $schoolDays;
        $cursor    = $from;
        while (true) {
            if (!$this->isWeekend($cursor) && !isset($holidays[$cursor->format('Y-m-d')])) {
                --$remaining;
                if ($remaining <= 0) {
                    return $cursor;
                }
            }
            $cursor = $cursor->modify('+1 day');
        }
    }

    /** @return array<string, true> */
    private function holidaySet(AcademicYear $year): array
    {
        $set = [];
        foreach ($this->nonWorkingDays->findByAcademicYearOrdered($year) as $day) {
            $set[$day->getDate()->format('Y-m-d')] = true;
        }

        return $set;
    }
}
