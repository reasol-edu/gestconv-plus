<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Teacher;
use App\Entity\TimeSlot;

/** Un tramo horario del tablón con los docentes ausentes en él. */
final readonly class BoardAbsenceSlot
{
    /**
     * @param list<BoardAbsenceRow> $rows
     * @param list<Teacher>         $guards profesorado de guardia ese día (sin quien tiene una ausencia cubierta por sustituto/a)
     */
    public function __construct(
        public TimeSlot $timeSlot,
        public array $rows,
        public array $guards = [],
    ) {}
}
