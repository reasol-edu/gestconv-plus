<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Entity\Group;
use App\Entity\Student;
use App\Entity\StudentExit;
use App\Repository\GroupRepository;
use App\Repository\StudentExitRepository;

/**
 * Construye el informe de salidas del aula en un rango de fechas: el resumen, el desglose por motivo (con los grupos y el
 * alumnado que más lo usan), el reparto por grupo y motivo agrupado por curso, y el alumnado con más salidas.
 */
class ExitStatisticsService
{
    /** Elementos de cada ranking de grupos o alumnado por motivo. */
    public const int TOP_PER_REASON = 5;

    /** Alumnado que aparece en el ranking general. */
    public const int TOP_STUDENTS = 10;

    public function __construct(
        private readonly GroupRepository $groups,
        private readonly StudentExitRepository $exits,
    ) {}

    public function build(EducationalCentre $centre, AcademicYear $year, \DateTimeImmutable $from, \DateTimeImmutable $to): ExitStatisticsReport
    {
        $exits  = $this->exits->findForStats($year, $from, $to);
        $groups = $this->groups->findByActiveYearOfCentreWithCourse($centre, $year);

        /** @var array<string, list<StudentExit>> $byGroup */
        $byGroup = [];
        /** @var array<string, list<StudentExit>> $byReason */
        $byReason = [];
        /** @var array<string, ExitReason> $reasonById */
        $reasonById = [];
        foreach ($exits as $exit) {
            $byGroup[$exit->getGroup()->getId()->toRfc4122()][] = $exit;
            $rid                                                = $exit->getReason()->getId()->toRfc4122();
            $byReason[$rid][]                                   = $exit;
            $reasonById[$rid]                                   = $exit->getReason();
        }

        $reasons = [];
        foreach ($byReason as $rid => $list) {
            $reasons[] = new ExitReasonStatistics(
                $reasonById[$rid],
                $this->totals($list),
                $this->topGroups($list),
                $this->topStudents($list, self::TOP_PER_REASON, false),
            );
        }
        usort($reasons, static fn (ExitReasonStatistics $a, ExitReasonStatistics $b): int => [$b->totals->exits, $a->reason->getName()] <=> [$a->totals->exits, $b->reason->getName()]);

        /** @var array<string, Course> $courseById */
        $courseById = [];
        /** @var array<string, list<Group>> $groupsByCourse */
        $groupsByCourse = [];
        foreach ($groups as $group) {
            $cid                    = $group->getCourse()->getId()->toRfc4122();
            $courseById[$cid]     ??= $group->getCourse();
            $groupsByCourse[$cid][] = $group;
        }

        $courses = [];
        foreach ($groupsByCourse as $cid => $courseGroups) {
            $rows       = [];
            $courseList = [];
            foreach ($courseGroups as $group) {
                $list       = $byGroup[$group->getId()->toRfc4122()] ?? [];
                $rows[]     = $this->row($group, $list);
                $courseList = [...$courseList, ...$list];
            }
            $courses[] = new ExitCourseStatistics($courseById[$cid], $rows, $this->row(null, $courseList));
        }

        return new ExitStatisticsReport($from, $to, $this->totals($exits), $reasons, $courses, $this->topStudents($exits, self::TOP_STUDENTS, true));
    }

    /** @param list<StudentExit> $exits */
    private function row(?Group $group, array $exits): ExitGroupRow
    {
        $byReason = [];
        foreach ($exits as $exit) {
            $rid            = $exit->getReason()->getId()->toRfc4122();
            $byReason[$rid] = ($byReason[$rid] ?? 0) + 1;
        }

        return new ExitGroupRow($group, $this->totals($exits), $byReason);
    }

    /** @param list<StudentExit> $exits */
    private function totals(array $exits): ExitTotals
    {
        $students = [];
        $groups   = [];
        $returned = $seconds = $max = 0;
        foreach ($exits as $exit) {
            $students[$exit->getStudent()->getId()->toRfc4122()] = true;
            $groups[$exit->getGroup()->getId()->toRfc4122()]     = true;
            $returnedAt                                          = $exit->getReturnedAt();
            if ($returnedAt !== null) {
                ++$returned;
                $out      = max(0, $returnedAt->getTimestamp() - $exit->getExitedAt()->getTimestamp());
                $seconds += $out;
                $max      = max($max, $out);
            }
        }

        return new ExitTotals(count($exits), count($students), count($groups), $returned, $seconds, $max);
    }

    /**
     * @param list<StudentExit> $exits
     * @return list<ExitGroupRanking>
     */
    private function topGroups(array $exits): array
    {
        /** @var array<string, array{group: Group, exits: int, students: array<string, true>}> $acc */
        $acc = [];
        foreach ($exits as $exit) {
            $gid                                                         = $exit->getGroup()->getId()->toRfc4122();
            $acc[$gid]                                                 ??= ['group' => $exit->getGroup(), 'exits' => 0, 'students' => []];
            ++$acc[$gid]['exits'];
            $acc[$gid]['students'][$exit->getStudent()->getId()->toRfc4122()] = true;
        }

        $rankings = array_map(static fn (array $a): ExitGroupRanking => new ExitGroupRanking($a['group'], $a['exits'], count($a['students'])), array_values($acc));
        usort($rankings, static fn (ExitGroupRanking $a, ExitGroupRanking $b): int => [$b->exits, $a->group->getName()] <=> [$a->exits, $b->group->getName()]);

        return array_slice($rankings, 0, self::TOP_PER_REASON);
    }

    /**
     * @param list<StudentExit> $exits
     * @return list<ExitStudentRanking>
     */
    private function topStudents(array $exits, int $limit, bool $withTopReason): array
    {
        /** @var array<string, array{student: Student, group: Group, at: \DateTimeImmutable, exits: int, seconds: int, reasons: array<string, array{reason: ExitReason, n: int}>}> $acc */
        $acc = [];
        foreach ($exits as $exit) {
            $sid = $exit->getStudent()->getId()->toRfc4122();
            $acc[$sid] ??= ['student' => $exit->getStudent(), 'group' => $exit->getGroup(), 'at' => $exit->getExitedAt(), 'exits' => 0, 'seconds' => 0, 'reasons' => []];
            ++$acc[$sid]['exits'];
            if ($exit->getExitedAt() >= $acc[$sid]['at']) {
                $acc[$sid]['at']    = $exit->getExitedAt();
                $acc[$sid]['group'] = $exit->getGroup();
            }
            $returnedAt = $exit->getReturnedAt();
            if ($returnedAt !== null) {
                $acc[$sid]['seconds'] += max(0, $returnedAt->getTimestamp() - $exit->getExitedAt()->getTimestamp());
            }
            $rid                                  = $exit->getReason()->getId()->toRfc4122();
            $acc[$sid]['reasons'][$rid]         ??= ['reason' => $exit->getReason(), 'n' => 0];
            ++$acc[$sid]['reasons'][$rid]['n'];
        }

        $rankings = [];
        foreach ($acc as $a) {
            $top = null;
            if ($withTopReason) {
                $best = null;
                foreach ($a['reasons'] as $r) {
                    if ($best === null || $r['n'] > $best['n']) {
                        $best = $r;
                    }
                }
                $top = $best['reason'] ?? null;
            }
            $rankings[] = new ExitStudentRanking($a['student'], $a['group'], $a['exits'], $a['seconds'], $top);
        }
        usort($rankings, static fn (ExitStudentRanking $a, ExitStudentRanking $b): int => [$b->exits, $b->seconds, $a->student->getName()->full()] <=> [$a->exits, $a->seconds, $b->student->getName()->full()]);

        return array_slice($rankings, 0, $limit);
    }
}
