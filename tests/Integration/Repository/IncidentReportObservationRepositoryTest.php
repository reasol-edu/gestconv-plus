<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\IncidentReportObservation;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\IncidentReportObservationRepository;
use App\Tests\Integration\RepositoryTestCase;

class IncidentReportObservationRepositoryTest extends RepositoryTestCase
{
    private IncidentReportObservationRepository $repo;
    private int $nextReportNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var IncidentReportObservationRepository $repo */
        $repo       = self::getContainer()->get(IncidentReportObservationRepository::class);
        $this->repo = $repo;
    }

    // ── findByIncidentReports ──────────────────────────────────────────────

    public function testFindByIncidentReportsGroupsObservationsByReportId(): void
    {
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('find.by.reports.obs');
        $reportWithObs = $this->makeReport('41000101', $teacher);
        $reportWithoutObs = $this->makeReport('41000102', $teacher);

        $observation = new IncidentReportObservation($reportWithObs, $teacher, new \DateTimeImmutable(), '<p>Texto</p>');
        $this->persist($observation);

        $map = $this->repo->findByIncidentReports([$reportWithObs, $reportWithoutObs]);

        self::assertCount(1, $map[$reportWithObs->getId()->toRfc4122()]);
        self::assertSame(
            $observation->getId()->toRfc4122(),
            $map[$reportWithObs->getId()->toRfc4122()][0]->getId()->toRfc4122(),
        );
        self::assertSame([], $map[$reportWithoutObs->getId()->toRfc4122()]);
    }

    public function testFindByIncidentReportsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repo->findByIncidentReports([]));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeReport(string $centreCode, Teacher $teacher): IncidentReport
    {
        $centre    = (new EducationalCentre())->setCode($centreCode)->setName('IES ' . $centreCode)->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA')->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE' . uniqid('', false));
        $category  = (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName('Contrarias')
            ->setSerious(false)
            ->setPosition(0);
        $behavior  = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Perturbación')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($centre, $year, $course, $group, $student, $category, $behavior, $teacher);

        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($teacher)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);
        $this->persist($report);

        return $report;
    }
}
