<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Repository\IssuePriorityRepository;
use App\Repository\IssueRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Construye el informe de incidencias registradas en un rango de fechas: el resumen, cómo se han cerrado, el desglose por
 * tipo (agrupado por tipo raíz) y por prioridad, el cumplimiento de plazos, los tiempos de cierre y de primera gestión, las
 * dependencias con más incidencias y los tipos más frecuentes, más lentos y con más retrasos.
 */
class IssueStatisticsService
{
    /** Elementos de cada ranking. */
    public const int TOP = 5;

    /** Máximo de meses que se desglosan (un curso entero cabe de sobra). */
    private const int MAX_MONTHS = 24;

    public function __construct(
        private readonly IssueRepository $issues,
        private readonly IssuePriorityRepository $priorities,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {}

    public function build(EducationalCentre $centre, AcademicYear $year, \DateTimeImmutable $from, \DateTimeImmutable $to): IssueStatisticsReport
    {
        $issues = $this->issues->findForStats($centre, $year, $from, $to);
        $first  = $this->issues->firstManagementDates($centre, $year, $from, $to);
        $today  = $this->clock->now();

        // Cómo se han cerrado.
        $byClosure = [];
        foreach ([IssueStatus::Solved, IssueStatus::NotApplicable, IssueStatus::Duplicate] as $status) {
            $list        = array_values(array_filter($issues, static fn (Issue $i): bool => $i->getStatus() === $status));
            $byClosure[] = $this->row($this->translator->trans('issue.status.' . $status->value, [], 'admin'), $list, $first, $today);
        }

        // Por tipo, agrupado por el tipo raíz.
        /** @var array<string, list<Issue>> $byType */
        $byType = [];
        /** @var array<string, IssueType> $typeById */
        $typeById = [];
        foreach ($issues as $issue) {
            $tid             = $issue->getType()->getId()->toRfc4122();
            $byType[$tid][]  = $issue;
            $typeById[$tid]  = $issue->getType();
        }
        /** @var array<string, array{root: string, rows: list<IssueStatsRow>, issues: list<Issue>}> $branchAcc */
        $branchAcc = [];
        /** @var list<IssueStatsRow> $typeRows */
        $typeRows = [];
        foreach ($byType as $tid => $list) {
            $type = $typeById[$tid];
            $root = $this->rootName($type);
            $r    = $this->row($type->getPath(), $list, $first, $today);

            $typeRows[]                 = $r;
            $branchAcc[$root]         ??= ['root' => $root, 'rows' => [], 'issues' => []];
            $branchAcc[$root]['rows'][] = $r;
            $branchAcc[$root]['issues'] = [...$branchAcc[$root]['issues'], ...$list];
        }
        ksort($branchAcc);
        $branches = [];
        foreach ($branchAcc as $b) {
            usort($b['rows'], static fn (IssueStatsRow $x, IssueStatsRow $y): int => [$y->registered, $x->label] <=> [$x->registered, $y->label]);
            $branches[] = new IssueTypeBranchStats($b['root'], $b['rows'], $this->row($b['root'], $b['issues'], $first, $today));
        }

        // Por prioridad.
        $byPriority = [];
        foreach ($this->priorities->findByCentreOrdered($centre) as $priority) {
            $list = array_values(array_filter($issues, static fn (Issue $i): bool => $i->getPriority()?->getId()->equals($priority->getId()) ?? false));
            if ($list !== []) {
                $byPriority[] = $this->row($priority->getName(), $list, $first, $today);
            }
        }
        $none = array_values(array_filter($issues, static fn (Issue $i): bool => $i->getPriority() === null));
        if ($none !== []) {
            $byPriority[] = $this->row($this->translator->trans('issue_stats.no_priority', [], 'admin'), $none, $first, $today);
        }

        // Por dependencia.
        /** @var array<string, list<Issue>> $byLocation */
        $byLocation = [];
        $noLocation = $this->translator->trans('issue_stats.no_location', [], 'admin');
        foreach ($issues as $issue) {
            $byLocation[$issue->getLocation()?->getName() ?? $noLocation][] = $issue;
        }
        $locationRows = [];
        foreach ($byLocation as $label => $list) {
            $locationRows[] = $this->row((string) $label, $list, $first, $today);
        }
        $locationRows = $this->top($locationRows, static fn (IssueStatsRow $r): int => $r->registered);

        // Rankings de tipos.
        $mostFrequent = $this->top($typeRows, static fn (IssueStatsRow $r): int => $r->registered);
        $slowest      = $this->top(
            array_values(array_filter($typeRows, static fn (IssueStatsRow $r): bool => $r->avgCloseSeconds !== null)),
            static fn (IssueStatsRow $r): int => $r->avgCloseSeconds ?? 0,
        );
        $mostLate = $this->top(
            array_values(array_filter($typeRows, static fn (IssueStatsRow $r): bool => $r->late() > 0)),
            static fn (IssueStatsRow $r): int => $r->late(),
        );

        return new IssueStatisticsReport(
            $from,
            $to,
            $this->row('', $issues, $first, $today),
            $byClosure,
            $branches,
            $byPriority,
            $locationRows,
            $mostFrequent,
            $slowest,
            $mostLate,
            $this->monthly($issues, $from, $to, $first, $today),
        );
    }

    /**
     * @param list<Issue> $issues
     * @param array<string, \DateTimeImmutable> $first
     * @return list<IssueStatsRow>
     */
    private function monthly(array $issues, \DateTimeImmutable $from, \DateTimeImmutable $to, array $first, \DateTimeImmutable $today): array
    {
        $rows  = [];
        $month = $from->modify('first day of this month')->setTime(0, 0);
        $last  = $to->modify('first day of this month')->setTime(0, 0);
        for ($n = 0; $month <= $last && $n < self::MAX_MONTHS; ++$n, $month = $month->modify('+1 month')) {
            $key  = $month->format('Y-m');
            $list = array_values(array_filter($issues, static fn (Issue $i): bool => $i->getCreatedAt()->format('Y-m') === $key));
            $rows[] = $this->row(
                $this->translator->trans('month.' . $month->format('n'), [], 'calendar') . ' ' . $month->format('Y'),
                $list,
                $first,
                $today,
            );
        }

        return $rows;
    }

    /**
     * @param list<Issue> $list
     * @param array<string, \DateTimeImmutable> $first
     */
    private function row(string $label, array $list, array $first, \DateTimeImmutable $today): IssueStatsRow
    {
        $open = $solved = $notApplicable = $duplicate = $overdue = 0;
        $closeTimes = [];
        $managementTimes = [];
        $withMax = $onTimeMax = $withRecommended = $onTimeRecommended = 0;

        foreach ($list as $issue) {
            match ($issue->getStatus()) {
                IssueStatus::Open, IssueStatus::InProgress => ++$open,
                IssueStatus::Solved                        => ++$solved,
                IssueStatus::NotApplicable                 => ++$notApplicable,
                IssueStatus::Duplicate                     => ++$duplicate,
            };

            if ($issue->isOverdue($today)) {
                ++$overdue;
            }

            $managed = $first[$issue->getId()->toRfc4122()] ?? null;
            if ($managed !== null) {
                $managementTimes[] = max(0, $managed->getTimestamp() - $issue->getCreatedAt()->getTimestamp());
            }

            $closedAt = $issue->getClosedAt();
            if ($issue->getStatus()->isClosed() && $closedAt !== null) {
                $closeTimes[] = max(0, $closedAt->getTimestamp() - $issue->getCreatedAt()->getTimestamp());

                $closedDay = $closedAt->format('Y-m-d');
                if ($issue->getMaxDue() !== null) {
                    ++$withMax;
                    if ($closedDay <= $issue->getMaxDue()->format('Y-m-d')) {
                        ++$onTimeMax;
                    }
                }
                if ($issue->getRecommendedDue() !== null) {
                    ++$withRecommended;
                    if ($closedDay <= $issue->getRecommendedDue()->format('Y-m-d')) {
                        ++$onTimeRecommended;
                    }
                }
            }
        }

        return new IssueStatsRow(
            $label,
            count($list),
            $open,
            $solved,
            $notApplicable,
            $duplicate,
            $overdue,
            $closeTimes !== [] ? intdiv(array_sum($closeTimes), count($closeTimes)) : null,
            $this->median($closeTimes),
            $managementTimes !== [] ? intdiv(array_sum($managementTimes), count($managementTimes)) : null,
            $withMax,
            $onTimeMax,
            $withRecommended,
            $onTimeRecommended,
        );
    }

    /** @param list<int> $values */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : intdiv($values[$mid - 1] + $values[$mid], 2);
    }

    /**
     * Las $TOP filas con más valor (a igual valor, por etiqueta).
     *
     * @param list<IssueStatsRow> $rows
     * @param \Closure(IssueStatsRow): int $value
     * @return list<IssueStatsRow>
     */
    private function top(array $rows, \Closure $value): array
    {
        usort($rows, static fn (IssueStatsRow $a, IssueStatsRow $b): int => [$value($b), $a->label] <=> [$value($a), $b->label]);

        return array_slice($rows, 0, self::TOP);
    }

    private function rootName(IssueType $type): string
    {
        $ancestors = $type->getAncestors();

        return ($ancestors !== [] ? $ancestors[array_key_last($ancestors)] : $type)->getName();
    }
}
