<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExitReason;
use App\Entity\Group;
use App\Entity\Student;

/** Un estudiante en un ranking del informe de salidas. */
final readonly class ExitStudentRanking
{
    public function __construct(
        public Student $student,
        /** Grupo de su salida más reciente. */
        public Group $group,
        public int $exits,
        /** Tiempo fuera en total (solo salidas con regreso), en segundos. */
        public int $seconds,
        /** Motivo más frecuente (null en los rankings que ya son de un motivo). */
        public ?ExitReason $topReason = null,
    ) {}
}
