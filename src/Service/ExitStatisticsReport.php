<?php

declare(strict_types=1);

namespace App\Service;

/** El informe de salidas del aula en un rango de fechas. */
final readonly class ExitStatisticsReport
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ExitTotals $totals,
        /** @var list<ExitReasonStatistics> los motivos con alguna salida, de más a menos usados */
        public array $reasons,
        /** @var list<ExitCourseStatistics> */
        public array $courses,
        /** @var list<ExitStudentRanking> el alumnado con más salidas, de cualquier motivo */
        public array $topStudents,
    ) {}
}
