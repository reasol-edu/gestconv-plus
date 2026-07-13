<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Course;

final readonly class CourseStatistics
{
    /**
     * @param list<GroupStatisticsRow> $rows
     */
    public function __construct(
        public Course $course,
        public array $rows,
        public GroupStatisticsRow $total,
    ) {}
}
