<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Course;

/** Los grupos de un curso en el informe de salidas, con su total. */
final readonly class ExitCourseStatistics
{
    public function __construct(
        public Course $course,
        /** @var list<ExitGroupRow> */
        public array $rows,
        public ExitGroupRow $total,
    ) {}
}
