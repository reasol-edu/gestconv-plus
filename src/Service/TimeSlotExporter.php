<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Repository\TimeSlotRepository;

class TimeSlotExporter
{
    public function __construct(
        private readonly TimeSlotRepository $timeSlots,
    ) {}

    /** @return array<string, mixed> */
    public function export(AcademicYear $year): array
    {
        $data = [
            'exported_at'   => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'academic_year' => $year->getName(),
            'time_slots'    => [],
        ];

        foreach ($this->timeSlots->findByAcademicYearOrdered($year) as $timeSlot) {
            $data['time_slots'][] = [
                'name'        => $timeSlot->getName(),
                'day_of_week' => $timeSlot->getDayOfWeek(),
                'start_time'  => $timeSlot->getStartTime()->format('H:i'),
                'end_time'    => $timeSlot->getEndTime()->format('H:i'),
            ];
        }

        return $data;
    }
}
