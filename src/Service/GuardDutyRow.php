<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Teacher;

final readonly class GuardDutyRow
{
    /**
     * @param string $key stable identifier ("H:i:s-H:i:s") for this start/end time combination,
     *                     used to select which rows go into the PDF (see TimeSlotController::pdf())
     * @param array<int, list<Teacher>> $guardsByDay indexed by TimeSlotComponent::DAYS (0 = lunes … 4 = viernes)
     */
    public function __construct(
        public string $key,
        public string $label,
        public \DateTimeImmutable $startTime,
        public \DateTimeImmutable $endTime,
        public array $guardsByDay,
    ) {}
}
