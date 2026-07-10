<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Service\GroupStatisticsService;
use App\Tests\Integration\RepositoryTestCase;

class GroupStatisticsServiceTest extends RepositoryTestCase
{
    private GroupStatisticsService $service;
    private int $nextNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var GroupStatisticsService $service */
        $service       = self::getContainer()->get(GroupStatisticsService::class);
        $this->service = $service;
    }

    public function testBuildsPerGroupProgrammeAndGrandTotals(): void
    {
        $centre = (new EducationalCentre())->setCode('41999')->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);

        // 'ASIR' sorts before 'DAW', so this is the expected programme order in the report.
        $daw    = (new Programme())->setName('DAW')->setAcademicYear($year);
        $dawY1  = (new ProgrammeYear())->setName('1º')->setProgramme($daw);
        $groupA = (new Group())->setName('1ºA')->setProgrammeYear($dawY1);
        $groupB = (new Group())->setName('1ºB')->setProgrammeYear($dawY1);

        $asir   = (new Programme())->setName('ASIR')->setAcademicYear($year);
        $asirY1 = (new ProgrammeYear())->setName('1º')->setProgramme($asir);
        $groupC = (new Group())->setName('1ºC')->setProgrammeYear($asirY1);

        $teacher = (new Teacher(new PersonName('Ana', 'Docente')))->setUsername('teacher.gss');

        $categoryNormal  = (new IncidentBehaviorCategory())->setEducationalCentre($centre)->setName('Leves')->setSerious(false)->setPosition(0);
        $categorySerious = (new IncidentBehaviorCategory())->setEducationalCentre($centre)->setName('Graves')->setSerious(true)->setPosition(1);
        $behaviorNormal  = (new IncidentBehavior())->setEducationalCentre($centre)->setCategory($categoryNormal)->setName('Falta leve')->setPosition(0)->setActive(true);
        $behaviorSerious = (new IncidentBehavior())->setEducationalCentre($centre)->setCategory($categorySerious)->setName('Falta grave')->setPosition(0)->setActive(true);

        $student1 = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-gss-1');
        $student2 = (new Student(new PersonName('Bea', 'López')))->setStudentId('NIE-gss-2');
        $student3 = (new Student(new PersonName('Cris', 'Ruiz')))->setStudentId('NIE-gss-3');

        $this->persist(
            $centre, $year, $daw, $dawY1, $groupA, $groupB, $asir, $asirY1, $groupC,
            $teacher, $categoryNormal, $categorySerious, $behaviorNormal, $behaviorSerious,
            $student1, $student2, $student3,
        );

        $from       = new \DateTimeImmutable('2026-01-01');
        $to         = new \DateTimeImmutable('2026-06-30');
        $inRange    = new \DateTimeImmutable('2026-03-15');
        $outOfRange = new \DateTimeImmutable('2025-09-01');

        // 1ºA: student1 has a normal report and a serious report.
        $r1 = $this->makeReport($year, $student1, $groupA, $teacher, $behaviorNormal, $inRange);
        $r2 = $this->makeReport($year, $student1, $groupA, $teacher, $behaviorSerious, $inRange);

        // 1ºA: student2 has two normal reports sharing the SAME sanction (counted once).
        $r3 = $this->makeReport($year, $student2, $groupA, $teacher, $behaviorNormal, $inRange);
        $r4 = $this->makeReport($year, $student2, $groupA, $teacher, $behaviorNormal, $inRange);
        $this->persist($r1, $r2, $r3, $r4);

        $sanction = (new Sanction())
            ->setAcademicYear($year)
            ->setStudent($student2)
            ->setGroup($groupA)
            ->setRegisteredBy($teacher)
            ->setDetails('<p>Sanción</p>');
        $this->persist($sanction);
        $r3->setSanction($sanction);
        $r4->setSanction($sanction);
        $this->flush();

        // 1ºA: report outside the date range must be excluded entirely.
        $rOut = $this->makeReport($year, $student1, $groupA, $teacher, $behaviorNormal, $outOfRange);
        $this->persist($rOut);

        // 1ºC (ASIR): a notified... no, a prescribed serious report.
        $r5 = $this->makeReport($year, $student3, $groupC, $teacher, $behaviorSerious, $inRange);
        $r5->setPrescribedAt($inRange);
        $this->persist($r5);

        $report = $this->service->build($centre, $year, $from, $to);

        self::assertCount(2, $report->programmes);

        // ── ASIR (alphabetically first) ──
        $asirStats = $report->programmes[0];
        self::assertSame('ASIR', $asirStats->programme->getName());
        self::assertCount(1, $asirStats->rows);

        $rowC = $asirStats->rows[0];
        self::assertSame('1ºC', $rowC->group?->getName());
        self::assertSame(1, $rowC->uniqueStudents);
        self::assertSame(0, $rowC->reportsNormal);
        self::assertSame(1, $rowC->reportsSerious);
        self::assertSame(0, $rowC->notifiedNormal + $rowC->notifiedSerious);
        self::assertSame(0, $rowC->sanctionedNormal + $rowC->sanctionedSerious);
        self::assertSame(0, $rowC->prescribedNormal);
        self::assertSame(1, $rowC->prescribedSerious);
        self::assertSame(0, $rowC->sanctionsCount);

        self::assertSame(1, $asirStats->total->uniqueStudents);
        self::assertSame(1, $asirStats->total->reportsSerious);

        // ── DAW ──
        $dawStats = $report->programmes[1];
        self::assertSame('DAW', $dawStats->programme->getName());
        self::assertCount(2, $dawStats->rows); // 1ºA and 1ºB, even though 1ºB has zero reports

        $rowA = $dawStats->rows[0];
        self::assertSame('1ºA', $rowA->group?->getName());
        self::assertSame(2, $rowA->uniqueStudents);
        self::assertSame(3, $rowA->reportsNormal);
        self::assertSame(1, $rowA->reportsSerious);
        self::assertSame(2, $rowA->sanctionedNormal);
        self::assertSame(0, $rowA->sanctionedSerious);
        self::assertSame(1, $rowA->sanctionsCount, 'The shared sanction on r3/r4 must be counted once.');

        $rowB = $dawStats->rows[1];
        self::assertSame('1ºB', $rowB->group?->getName());
        self::assertSame(0, $rowB->uniqueStudents);
        self::assertSame(0, $rowB->reportsNormal);
        self::assertSame(0, $rowB->reportsSerious);
        self::assertSame(0, $rowB->sanctionsCount);

        self::assertSame(2, $dawStats->total->uniqueStudents);
        self::assertSame(3, $dawStats->total->reportsNormal);
        self::assertSame(1, $dawStats->total->reportsSerious);
        self::assertSame(2, $dawStats->total->sanctionedNormal);
        self::assertSame(1, $dawStats->total->sanctionsCount);

        // ── Grand total across both programmes ──
        self::assertSame(3, $report->grandTotal->uniqueStudents);
        self::assertSame(3, $report->grandTotal->reportsNormal);
        self::assertSame(2, $report->grandTotal->reportsSerious);
        self::assertSame(2, $report->grandTotal->sanctionedNormal);
        self::assertSame(0, $report->grandTotal->sanctionedSerious);
        self::assertSame(0, $report->grandTotal->prescribedNormal);
        self::assertSame(1, $report->grandTotal->prescribedSerious);
        self::assertSame(1, $report->grandTotal->sanctionsCount);
    }

    private function makeReport(
        AcademicYear $year,
        Student $student,
        Group $group,
        Teacher $teacher,
        IncidentBehavior $behavior,
        \DateTimeImmutable $occurredAt,
    ): IncidentReport {
        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(++$this->nextNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($teacher)
            ->setOccurredAt($occurredAt)
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);

        return $report;
    }
}
