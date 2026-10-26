<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Teacher;

/** Un docente ausente en un tramo del tablón, con la tarea que dejó para ese tramo si la hay. */
final readonly class BoardAbsenceRow
{
    public function __construct(
        public Teacher $teacher,
        public ?Activity $activity,
    ) {}
}
