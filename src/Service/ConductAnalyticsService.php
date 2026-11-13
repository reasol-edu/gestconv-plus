<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Repository\GroupRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;

/**
 * Analítica de convivencia: las series de los gráficos (evolución mensual, día de la semana, hora, conductas,
 * cursos y grupos, concentración por estudiante) a partir de los mismos partes que usan las estadísticas por
 * grupo, con la misma definición de «parte grave», de modo que los totales coinciden. Es un informe del centro
 * entero (para el equipo directivo): no se filtra por quién consulta.
 */
final class ConductAnalyticsService
{
    /** Conductas y grupos que se muestran en los gráficos de «los más…». */
    public const int TOP = 10;

    /** Estudiantes con este número de partes o más se consideran «reincidentes» en la cifra de concentración. */
    public const int REPEAT_THRESHOLD = 3;

    /** El último tramo de la concentración agrupa a los de este número de partes o más. */
    private const int LAST_BUCKET = 5;

    public function __construct(
        private readonly IncidentReportRepository $incidentReports,
        private readonly SanctionRepository $sanctions,
        private readonly GroupRepository $groups,
    ) {}

    /**
     * Primer y último día con partes del curso, o null si todavía no hay (rango por defecto de la pantalla).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    public function defaultRange(EducationalCentre $centre, AcademicYear $year): ?array
    {
        return $this->incidentReports->findOccurredAtBounds($centre, $year);
    }

    public function build(EducationalCentre $centre, AcademicYear $year, \DateTimeImmutable $from, \DateTimeImmutable $to): ConductAnalyticsReport
    {
        $reports        = $this->incidentReports->findForGroupStats($centre, $year, $from, $to);
        $sanctionDates  = $this->sanctions->findCreatedAtBetween($year, $from, $to);
        $groupsOfYear   = $this->groups->findByActiveYearOfCentreWithCourse($centre, $year);
        $studentsByGroup = $this->groups->findCountsByAcademicYear($year, $groupsOfYear);

        $months = $this->emptyMonths($from, $to);
        $weekdays = array_fill(1, 7, 0);
        $hours    = [];

        $serious = $sanctioned = $expelled = 0;
        $perStudent = [];
        $behaviourCount = [];
        $totalBehaviours = 0;
        $groupAgg = [];

        foreach ($reports as $report) {
            $isSerious = $report->hasSeriousBehavior();
            $at        = $report->getOccurredAt();
            $key       = $at->format('Y-m');

            if (isset($months[$key])) {
                $isSerious ? ++$months[$key]['serious'] : ++$months[$key]['contrary'];
            }
            ++$weekdays[(int) $at->format('N')];
            $hour = (int) $at->format('G');
            $hours[$hour] = ($hours[$hour] ?? 0) + 1;

            $serious    += $isSerious ? 1 : 0;
            $sanctioned += $report->getSanction() !== null ? 1 : 0;
            $expelled   += $report->isExpelledFromClass() ? 1 : 0;

            $studentId              = $report->getStudent()->getId()->toRfc4122();
            $perStudent[$studentId] = ($perStudent[$studentId] ?? 0) + 1;

            foreach ($report->getBehaviors() as $behaviour) {
                ++$totalBehaviours;
                $id = $behaviour->getId()->toRfc4122();
                $behaviourCount[$id] ??= ['name' => $behaviour->getName(), 'category' => $behaviour->getCategory()->getName(), 'serious' => $behaviour->getCategory()->isSerious(), 'count' => 0];
                ++$behaviourCount[$id]['count'];
            }

            $group   = $report->getGroup();
            $groupId = $group->getId()->toRfc4122();
            $groupAgg[$groupId] ??= ['name' => $group->getName(), 'course' => $group->getCourse()->getName(), 'courseId' => $group->getCourse()->getId()->toRfc4122(), 'reports' => 0, 'students' => $studentsByGroup[$groupId]['students'] ?? 0];
            ++$groupAgg[$groupId]['reports'];
        }

        foreach ($sanctionDates as $date) {
            $key = $date->format('Y-m');
            if (isset($months[$key])) {
                ++$months[$key]['sanctions'];
            }
        }

        // Los grupos sin partes también cuentan para el denominador de su curso.
        $courseStudents = [];
        $courseNames    = [];
        foreach ($groupsOfYear as $group) {
            $courseId = $group->getCourse()->getId()->toRfc4122();
            $courseNames[$courseId] = $group->getCourse()->getName();
            $courseStudents[$courseId] = ($courseStudents[$courseId] ?? 0) + ($studentsByGroup[$group->getId()->toRfc4122()]['students'] ?? 0);
        }

        $courseReports = [];
        foreach ($groupAgg as $row) {
            $courseReports[$row['courseId']] = ($courseReports[$row['courseId']] ?? 0) + $row['reports'];
            $courseNames[$row['courseId']] ??= $row['course'];
        }

        $courses = [];
        foreach ($courseReports as $courseId => $count) {
            $courses[] = ['name' => $courseNames[$courseId], 'reports' => $count, 'students' => $courseStudents[$courseId] ?? 0, 'per100' => self::per100($count, $courseStudents[$courseId] ?? 0)];
        }
        usort($courses, static fn (array $a, array $b): int => [$b['reports'], $a['name']] <=> [$a['reports'], $b['name']]);

        $groupRows = array_map(
            static fn (array $row): array => ['name' => $row['name'], 'course' => $row['course'], 'reports' => $row['reports'], 'students' => $row['students'], 'per100' => self::per100($row['reports'], $row['students'])],
            array_values($groupAgg),
        );
        usort($groupRows, static fn (array $a, array $b): int => [$b['reports'], $a['name']] <=> [$a['reports'], $b['name']]);

        $behaviourRows = array_values($behaviourCount);
        usort($behaviourRows, static fn (array $a, array $b): int => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        [$concentration, $repeatStudents, $repeatReports] = $this->concentration($perStudent);

        return new ConductAnalyticsReport(
            $from,
            $to,
            count($reports),
            $serious,
            count($perStudent),
            $sanctioned,
            $expelled,
            count($sanctionDates),
            $totalBehaviours,
            $repeatStudents,
            $repeatReports,
            array_values($months),
            $this->weekdayRows($weekdays),
            $this->hourRows($hours),
            array_slice($behaviourRows, 0, self::TOP),
            $courses,
            array_slice($groupRows, 0, self::TOP),
            $concentration,
        );
    }

    /**
     * Un punto por cada mes natural del rango, de septiembre a junio o lo que toque, aunque no haya partes.
     *
     * @return array<string, array{key: string, year: int, month: int, contrary: int, serious: int, sanctions: int}>
     */
    private function emptyMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $months = [];
        $cursor = $from->modify('first day of this month')->setTime(0, 0, 0);
        $last   = $to->modify('first day of this month')->setTime(0, 0, 0);

        while ($cursor <= $last) {
            $key          = $cursor->format('Y-m');
            $months[$key] = ['key' => $key, 'year' => (int) $cursor->format('Y'), 'month' => (int) $cursor->format('n'), 'contrary' => 0, 'serious' => 0, 'sanctions' => 0];
            $cursor       = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * @param array<int, int> $counts Por día ISO (1 = lunes … 7 = domingo)
     *
     * @return list<array{day: int, count: int}>
     */
    private function weekdayRows(array $counts): array
    {
        $rows = [];
        foreach ($counts as $day => $count) {
            if ($day <= 5 || $count > 0) {
                $rows[] = ['day' => $day, 'count' => $count];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, int> $counts
     *
     * @return list<array{hour: int, count: int}>
     */
    private function hourRows(array $counts): array
    {
        if ($counts === []) {
            return [];
        }

        $rows = [];
        for ($hour = min(array_keys($counts)); $hour <= max(array_keys($counts)); $hour++) {
            $rows[] = ['hour' => $hour, 'count' => $counts[$hour] ?? 0];
        }

        return $rows;
    }

    /**
     * @param array<string, int> $perStudent Partes por estudiante
     *
     * @return array{0: list<array{bucket: string, students: int, reports: int}>, 1: int, 2: int}
     */
    private function concentration(array $perStudent): array
    {
        $students = array_fill(1, self::LAST_BUCKET, 0);
        $reports  = array_fill(1, self::LAST_BUCKET, 0);

        $repeatStudents = $repeatReports = 0;
        foreach ($perStudent as $count) {
            $slot = min($count, self::LAST_BUCKET);
            ++$students[$slot];
            $reports[$slot] += $count;
            if ($count >= self::REPEAT_THRESHOLD) {
                ++$repeatStudents;
                $repeatReports += $count;
            }
        }

        $buckets = [];
        for ($i = 1; $i <= self::LAST_BUCKET; $i++) {
            $buckets[] = ['bucket' => $i === self::LAST_BUCKET ? $i . '+' : (string) $i, 'students' => $students[$i], 'reports' => $reports[$i]];
        }

        return [$buckets, $repeatStudents, $repeatReports];
    }

    /** Partes por cada 100 estudiantes (un decimal); null cuando no hay estudiantes con los que compararlos. */
    private static function per100(int $reports, int $students): ?float
    {
        return $students > 0 ? round($reports / $students * 100, 1) : null;
    }
}
