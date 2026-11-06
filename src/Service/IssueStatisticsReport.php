<?php

declare(strict_types=1);

namespace App\Service;

/** El informe de incidencias registradas en un rango de fechas. */
final readonly class IssueStatisticsReport
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public IssueStatsRow $totals,
        /** @var list<IssueStatsRow> cómo se han cerrado: solucionadas, no procede, duplicadas */
        public array $byClosure,
        /** @var list<IssueTypeBranchStats> */
        public array $branches,
        /** @var list<IssueStatsRow> de la más a la menos urgente, y las que no tienen */
        public array $byPriority,
        /** @var list<IssueStatsRow> las dependencias con más incidencias */
        public array $byLocation,
        /** @var list<IssueStatsRow> los tipos con más incidencias */
        public array $mostFrequent,
        /** @var list<IssueStatsRow> los tipos que más tardan en cerrarse */
        public array $slowest,
        /** @var list<IssueStatsRow> los tipos con más incidencias fuera de plazo */
        public array $mostLate,
        /** @var list<IssueStatsRow> las registradas cada mes y qué ha pasado con ellas */
        public array $monthly,
    ) {}
}
