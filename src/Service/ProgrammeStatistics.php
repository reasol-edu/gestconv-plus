<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Programme;

final readonly class ProgrammeStatistics
{
    /**
     * @param list<GroupStatisticsRow> $rows
     */
    public function __construct(
        public Programme $programme,
        public array $rows,
        public GroupStatisticsRow $total,
    ) {}
}
