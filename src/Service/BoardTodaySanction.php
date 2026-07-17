<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;
use App\Entity\Student;

final readonly class BoardTodaySanction
{
    public function __construct(
        public Student $student,
        public Group $group,
        public string $label,
    ) {}
}
