<?php

declare(strict_types=1);

namespace App\Service;

final readonly class GroupStatisticsReport
{
    /**
     * @param list<ProgrammeStatistics> $programmes
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $programmes,
        public GroupStatisticsRow $grandTotal,
    ) {}
}
