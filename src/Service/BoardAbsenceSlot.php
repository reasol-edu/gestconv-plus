<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TimeSlot;

/** Un tramo horario del tablón con los docentes ausentes en él. */
final readonly class BoardAbsenceSlot
{
    /** @param list<BoardAbsenceRow> $rows */
    public function __construct(
        public TimeSlot $timeSlot,
        public array $rows,
    ) {}
}
