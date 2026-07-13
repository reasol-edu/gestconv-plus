<?php

declare(strict_types=1);

namespace App\Service;

final readonly class GroupStatisticsReport
{
    /**
     * @param list<CourseStatistics> $courses
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public array $courses,
        public GroupStatisticsRow $grandTotal,
    ) {}
}
