<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;

/** Un grupo en un ranking del informe de salidas. */
final readonly class ExitGroupRanking
{
    public function __construct(
        public Group $group,
        public int $exits,
        public int $students,
    ) {}
}
