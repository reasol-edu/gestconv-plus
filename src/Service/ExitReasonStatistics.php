<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExitReason;

/** Las cifras de un motivo (tipo) de salida, con los grupos y el alumnado que más lo usan. */
final readonly class ExitReasonStatistics
{
    public function __construct(
        public ExitReason $reason,
        public ExitTotals $totals,
        /** @var list<ExitGroupRanking> */
        public array $topGroups,
        /** @var list<ExitStudentRanking> */
        public array $topStudents,
    ) {}
}
