<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Service\ConductAnalyticsService;
use App\Service\GroupStatisticsService;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** Series de los gráficos de la analítica de convivencia. */
class ConductAnalyticsServiceTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private ConductAnalyticsService $service;
    private int $nextNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ConductAnalyticsService $service */
        $service       = self::getContainer()->get(ConductAnalyticsService::class);
        $this->service = $service;
    }

    public function testBuildsEverySeriesAndTheFigures(): void
    {
        $w      = $this->world();
        $report = $this->service->build($w['centre'], $w['year'], new \DateTimeImmutable('2025-09-01'), new \DateTimeImmutable('2026-02-28'));

        // Cifras: 5 partes dentro del rango (el de marzo queda fuera).
        self::assertSame(5, $report->totalReports);
        self::assertSame(2, $report->seriousReports);
        self::assertSame(3, $report->contraryReports());
        self::assertSame(3, $report->students);
        self::assertSame(1, $report->sanctionedReports);
        self::assertSame(1, $report->expelledReports);
        self::assertSame(1, $report->sanctions);
        self::assertSame(6, $report->totalBehaviours, 'Un parte con dos conductas cuenta dos conductas.');
        self::assertFalse($report->isEmpty());

        // Evolución mensual: un punto por mes natural, también los meses sin partes.
        self::assertSame(['2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02'], array_column($report->months, 'key'));
        self::assertSame([1, 1, 0, 1, 0, 0], array_column($report->months, 'contrary'));
        self::assertSame([0, 1, 0, 0, 0, 1], array_column($report->months, 'serious'));
        self::assertSame([0, 0, 0, 1, 0, 0], array_column($report->months, 'sanctions'));
        self::assertSame(9, $report->months[0]['month']);
        self::assertSame(2025, $report->months[0]['year']);

        // Día de la semana: lunes a viernes siempre, y el sábado porque tiene un parte.
        self::assertSame([1, 2, 3, 4, 5, 6], array_column($report->weekdays, 'day'));
        self::assertSame([1, 1, 1, 1, 0, 1], array_column($report->weekdays, 'count'));

        // Hora: contigua entre la primera y la última con partes.
        self::assertSame([9, 10, 11, 12], array_column($report->hours, 'hour'));
        self::assertSame([1, 2, 1, 1], array_column($report->hours, 'count'));

        // Conductas, de más a menos registradas.
        self::assertSame(['Falta leve', 'Falta grave', 'Retraso'], array_column($report->behaviours, 'name'));
        self::assertSame([3, 2, 1], array_column($report->behaviours, 'count'));
        self::assertTrue($report->behaviours[1]['serious']);
        self::assertSame('Graves', $report->behaviours[1]['category']);

        // Cursos y grupos, con los partes por cada 100 estudiantes.
        self::assertSame(['DAW', 'ASIR'], array_column($report->courses, 'name'));
        self::assertSame([4, 1], array_column($report->courses, 'reports'));
        // 1ºC tiene tres estudiantes (s3, s5 y s6): 1 parte de 3 = 33,3 por cada 100.
        self::assertSame([100.0, 33.3], array_column($report->courses, 'per100'));
        self::assertSame(['1ºA', '1ºC'], array_column($report->groups, 'name'));
        self::assertSame([4, 3], array_column($report->groups, 'students'));
        self::assertSame('DAW', $report->groups[0]['course']);

        // Concentración: s1 tiene 3 partes, s2 y s3 uno.
        self::assertSame(['1', '2', '3', '4', '5+'], array_column($report->concentration, 'bucket'));
        self::assertSame([2, 0, 1, 0, 0], array_column($report->concentration, 'students'));
        self::assertSame([2, 0, 3, 0, 0], array_column($report->concentration, 'reports'));
        self::assertSame(1, $report->repeatStudents);
        self::assertSame(3, $report->repeatReports);
        self::assertSame(60.0, $report->repeatShare());
    }

    public function testTotalsMatchTheGroupStatisticsReportForTheSameRange(): void
    {
        $w    = $this->world();
        $from = new \DateTimeImmutable('2025-09-01');
        $to   = new \DateTimeImmutable('2026-03-31');

        $analytics = $this->service->build($w['centre'], $w['year'], $from, $to);
        /** @var GroupStatisticsService $groupStats */
        $groupStats = self::getContainer()->get(GroupStatisticsService::class);
        $total      = $groupStats->build($w['centre'], $w['year'], $from, $to)->grandTotal;

        self::assertSame($total->reportsTotal(), $analytics->totalReports);
        self::assertSame($total->reportsSerious, $analytics->seriousReports);
        self::assertSame($total->uniqueStudents, $analytics->students);
        self::assertSame($total->sanctionedNormal + $total->sanctionedSerious, $analytics->sanctionedReports);
        self::assertSame(6, $analytics->totalReports, 'Con el rango ampliado entra también el parte de marzo.');
    }

    public function testNothingFromAnotherCentreIsMixedIn(): void
    {
        $w     = $this->world();
        $other = $this->world('b');

        $mine = $this->service->build($w['centre'], $w['year'], new \DateTimeImmutable('2025-09-01'), new \DateTimeImmutable('2026-03-31'));

        self::assertSame(6, $mine->totalReports);
        self::assertNotSame($w['centre']->getId()->toRfc4122(), $other['centre']->getId()->toRfc4122());
    }

    public function testAnEmptyRangeStillHasItsMonthsAndIsEmpty(): void
    {
        $w      = $this->world();
        $report = $this->service->build($w['centre'], $w['year'], new \DateTimeImmutable('2024-01-10'), new \DateTimeImmutable('2024-03-05'));

        self::assertTrue($report->isEmpty());
        self::assertSame(['2024-01', '2024-02', '2024-03'], array_column($report->months, 'key'));
        self::assertSame([], $report->hours);
        self::assertSame([], $report->behaviours);
        self::assertSame([1, 2, 3, 4, 5], array_column($report->weekdays, 'day'), 'Sin fines de semana cuando no hay partes en ellos.');
        self::assertNull($report->repeatShare());
        self::assertSame([0, 0, 0, 0, 0], array_column($report->concentration, 'students'));
    }

    public function testTheDefaultRangeSpansTheFirstAndLastReportOfTheYear(): void
    {
        $w = $this->world();

        $range = $this->service->defaultRange($w['centre'], $w['year']);

        self::assertNotNull($range);
        self::assertSame('2025-09-15', $range[0]->format('Y-m-d'));
        self::assertSame('2026-03-10', $range[1]->format('Y-m-d'));

        $empty = (new AcademicYear())->setName('2030-2031')->setEducationalCentre($w['centre']);
        $this->persist($empty);
        self::assertNull($this->service->defaultRange($w['centre'], $empty));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear} */
    private function world(string $suffix = 'a'): array
    {
        self::mockTime('2025-12-10 10:00:00');

        $centre = (new EducationalCentre())->setCode('43' . substr(md5($suffix . 'cas'), 0, 6))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);

        $daw    = (new Course())->setName('DAW')->setAcademicYear($year);
        $groupA = (new Group())->setName('1ºA')->setCourse($daw);
        $groupB = (new Group())->setName('1ºB')->setCourse($daw);
        $asir   = (new Course())->setName('ASIR')->setAcademicYear($year);
        $groupC = (new Group())->setName('1ºC')->setCourse($asir);

        $teacher = (new Teacher(new PersonName('Ana', 'Docente')))->setUsername('teacher.cas.' . $suffix);

        $leves  = (new IncidentBehaviorCategory())->setEducationalCentre($centre)->setName('Leves')->setSerious(false)->setPosition(0);
        $graves = (new IncidentBehaviorCategory())->setEducationalCentre($centre)->setName('Graves')->setSerious(true)->setPosition(1);
        $leve   = (new IncidentBehavior())->setEducationalCentre($centre)->setCategory($leves)->setName('Falta leve')->setPosition(0)->setActive(true);
        $retraso = (new IncidentBehavior())->setEducationalCentre($centre)->setCategory($leves)->setName('Retraso')->setPosition(1)->setActive(true);
        $grave  = (new IncidentBehavior())->setEducationalCentre($centre)->setCategory($graves)->setName('Falta grave')->setPosition(0)->setActive(true);

        $s1 = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-cas-1-' . $suffix);
        $s2 = (new Student(new PersonName('Bea', 'López')))->setStudentId('NIE-cas-2-' . $suffix);
        $s3 = (new Student(new PersonName('Cris', 'Ruiz')))->setStudentId('NIE-cas-3-' . $suffix);
        $s4 = (new Student(new PersonName('Dani', 'Soto')))->setStudentId('NIE-cas-4-' . $suffix);
        $s5 = (new Student(new PersonName('Eva', 'Vega')))->setStudentId('NIE-cas-5-' . $suffix);
        $s6 = (new Student(new PersonName('Fer', 'Mora')))->setStudentId('NIE-cas-6-' . $suffix);
        foreach ([$s1, $s2, $s3, $s4] as $student) {
            $student->addGroup($groupA);
        }
        foreach ([$s5, $s6] as $student) {
            $student->addGroup($groupC);
        }
        $s3->addGroup($groupC); // s3 figura en 1ºC (donde tiene su parte) y en 1ºA

        $this->persist($centre, $year, $daw, $groupA, $groupB, $asir, $groupC, $teacher, $leves, $graves, $leve, $retraso, $grave, $s1, $s2, $s3, $s4, $s5, $s6);

        $reports = [
            $this->report($year, $s1, $groupA, $teacher, [$leve], '2025-09-15 09:10'),            // lunes
            $this->report($year, $s1, $groupA, $teacher, [$grave], '2025-10-14 10:30'),           // martes, grave
            $this->report($year, $s1, $groupA, $teacher, [$leve, $retraso], '2025-10-16 10:40'),  // jueves, dos conductas
            $this->report($year, $s2, $groupA, $teacher, [$leve], '2025-12-03 12:00'),            // miércoles
            $this->report($year, $s3, $groupC, $teacher, [$grave], '2026-02-07 11:00'),           // sábado, grave
            $this->report($year, $s2, $groupA, $teacher, [$leve], '2026-03-10 09:00'),            // fuera del rango de la mayoría de las pruebas
        ];
        $reports[2]->setExpelledFromClass(true);
        $this->persist(...$reports);

        $sanction = (new Sanction())->setAcademicYear($year)->setStudent($s2)->setGroup($groupA)->setRegisteredBy($teacher)->setDetails('<p>Sanción</p>');
        $this->persist($sanction);
        $reports[3]->setSanction($sanction);
        $this->flush();

        return ['centre' => $centre, 'year' => $year];
    }

    /** @param list<IncidentBehavior> $behaviours */
    private function report(AcademicYear $year, Student $student, Group $group, Teacher $teacher, array $behaviours, string $at): IncidentReport
    {
        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(++$this->nextNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($teacher)
            ->setOccurredAt(new \DateTimeImmutable($at))
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false);
        foreach ($behaviours as $behaviour) {
            $report->addBehavior($behaviour);
        }

        return $report;
    }
}
