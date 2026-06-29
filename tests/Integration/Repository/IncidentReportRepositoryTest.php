<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\IncidentReportRepository;
use App\Tests\Integration\RepositoryTestCase;

class IncidentReportRepositoryTest extends RepositoryTestCase
{
    private IncidentReportRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var IncidentReportRepository $repo */
        $repo       = self::getContainer()->get(IncidentReportRepository::class);
        $this->repo = $repo;
    }

    // ── Visibilidad: administrador global ────────────────────────────────────

    public function testGlobalAdminSeesAllReportsOfCentre(): void
    {
        $world  = $this->makeWorld();
        $admin  = $this->makeTeacher('admin.global', admin: true);
        $report = $this->makeReport($world);
        $this->persist($admin, $report);

        $results = $this->repo->createFilteredQuery($world['centre'], $admin)->getResult();

        self::assertCount(1, $results);
    }

    // ── Visibilidad: administrador de centro ─────────────────────────────────

    public function testCentreAdminSeesAllReportsOfHisCentre(): void
    {
        $world  = $this->makeWorld();
        $cadmin = $this->makeTeacher('cadmin.vis');
        $report = $this->makeReport($world);
        $this->persist($cadmin, $report);
        $world['centre']->addAdmin($cadmin);
        $this->flush();

        $results = $this->repo->createFilteredQuery($world['centre'], $cadmin)->getResult();

        self::assertCount(1, $results);
    }

    public function testCentreAdminDoesNotSeeReportsOfAnotherCentre(): void
    {
        $worldA = $this->makeWorld('A');
        $worldB = $this->makeWorld('B');
        $cadmin = $this->makeTeacher('cadmin.other');
        $report = $this->makeReport($worldA);
        $this->persist($cadmin, $report);
        $worldA['centre']->addAdmin($cadmin);
        $this->flush();

        // Query is scoped to centreB, so cadmin (admin of centreA) should see nothing
        $results = $this->repo->createFilteredQuery($worldB['centre'], $cadmin)->getResult();

        self::assertCount(0, $results);
    }

    // ── Visibilidad: docente sin privilegios ─────────────────────────────────

    public function testNonAdminTeacherOnlySeesOwnReports(): void
    {
        $world  = $this->makeWorld();
        $t1     = $this->makeTeacher('t1.own');
        $t2     = $this->makeTeacher('t2.other');
        $r1     = $this->makeReport($world, creator: $t1);
        $r2     = $this->makeReport($world, creator: $t2);
        $this->persist($t1, $t2, $r1, $r2);

        $results = $this->repo->createFilteredQuery($world['centre'], $t1)->getResult();

        self::assertCount(1, $results);
        self::assertSame($r1->getId(), $results[0]->getId());
    }

    public function testTutorSeesOwnAndGroupReports(): void
    {
        $world  = $this->makeWorld();
        $tutor  = $this->makeTeacher('tutor.sees');
        $other  = $this->makeTeacher('other.creator');
        $rOwn   = $this->makeReport($world, creator: $tutor);
        $rGroup = $this->makeReport($world, creator: $other);
        $this->persist($tutor, $other, $rOwn, $rGroup);
        $world['group']->addTutor($tutor);
        $this->flush();

        $results = $this->repo->createFilteredQuery($world['centre'], $tutor)->getResult();

        self::assertCount(2, $results);
    }

    // ── Filtro: ownOnly ──────────────────────────────────────────────────────

    public function testOwnOnlyFilterRestrictsToCreator(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeTeacher('admin.ownonly', admin: true);
        $t2    = $this->makeTeacher('t2.ownonly');
        $r1    = $this->makeReport($world, creator: $admin);
        $r2    = $this->makeReport($world, creator: $t2);
        $this->persist($admin, $t2, $r1, $r2);

        $results = $this->repo->createFilteredQuery(
            $world['centre'],
            $admin,
            ['ownOnly' => true],
        )->getResult();

        self::assertCount(1, $results);
        self::assertSame($r1->getId(), $results[0]->getId());
    }

    // ── Filtro: expelled ─────────────────────────────────────────────────────

    public function testExpelledFilterReturnsOnlyExpelledReports(): void
    {
        $world    = $this->makeWorld();
        $admin    = $this->makeTeacher('admin.expelled', admin: true);
        $expelled = $this->makeReport($world, creator: $admin, expelled: true);
        $normal   = $this->makeReport($world, creator: $admin, expelled: false);
        $this->persist($admin, $expelled, $normal);

        $results = $this->repo->createFilteredQuery(
            $world['centre'],
            $admin,
            ['expelled' => true],
        )->getResult();

        self::assertCount(1, $results);
        self::assertTrue($results[0]->isExpelledFromClass());
    }

    // ── Filtro: serious ──────────────────────────────────────────────────────

    public function testSeriousFilterReturnsOnlyReportsWithSeriousBehaviors(): void
    {
        $world   = $this->makeWorld();
        $admin   = $this->makeTeacher('admin.serious', admin: true);
        $serious = $this->makeReport($world, creator: $admin, serious: true);
        $minor   = $this->makeReport($world, creator: $admin, serious: false);
        $this->persist($admin, $serious, $minor);

        $results = $this->repo->createFilteredQuery(
            $world['centre'],
            $admin,
            ['serious' => true],
        )->getResult();

        self::assertCount(1, $results);
    }

    // ── countRecentByCentre ──────────────────────────────────────────────────

    public function testCountRecentByCentreCountsOwnReportsForRegularTeacher(): void
    {
        $world = $this->makeWorld();
        $t1    = $this->makeTeacher('t1.count');
        $t2    = $this->makeTeacher('t2.count');
        $r1    = $this->makeReport($world, creator: $t1);
        $r2    = $this->makeReport($world, creator: $t2);
        $this->persist($t1, $t2, $r1, $r2);

        $count = $this->repo->countRecentByCentre($world['centre'], $t1, 30);

        self::assertSame(1, $count);
    }

    public function testCountRecentByCentreCountsAllForAdmin(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeTeacher('admin.count', admin: true);
        $t1    = $this->makeTeacher('t1.count2');
        $r1    = $this->makeReport($world, creator: $t1);
        $r2    = $this->makeReport($world, creator: $t1);
        $this->persist($admin, $t1, $r1, $r2);

        $count = $this->repo->countRecentByCentre($world['centre'], $admin, 30);

        self::assertSame(2, $count);
    }

    // ── searchStudentGroupPairs ───────────────────────────────────────────────

    public function testSearchStudentGroupPairsFindsStudentByLastName(): void
    {
        $world = $this->makeWorld();
        $world['group']->addStudent($world['student']);
        $this->flush();

        $pairs = $this->repo->searchStudentGroupPairs($world['centre'], 'García', 10);

        self::assertCount(1, $pairs);
        self::assertSame($world['student']->getId(), $pairs[0]['student']->getId());
        self::assertSame($world['group']->getId(), $pairs[0]['group']->getId());
    }

    public function testSearchStudentGroupPairsReturnsEmptyWhenNoMatch(): void
    {
        $world = $this->makeWorld();
        $world['group']->addStudent($world['student']);
        $this->flush();

        $pairs = $this->repo->searchStudentGroupPairs($world['centre'], 'NoExiste', 10);

        self::assertCount(0, $pairs);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior}
     */
    private function makeWorld(string $suffix = ''): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'x'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA' . $suffix)->setProgrammeYear($level);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE' . $suffix . uniqid('', false));
        $behavior  = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setName('Perturbación')
            ->setPosition(0)
            ->setSerious(false)
            ->setActive(true);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $programme, $level, $group, $student, $behavior);

        return compact('centre', 'year', 'group', 'student', 'behavior');
    }

    /**
     * @param array{centre: EducationalCentre, group: Group, student: Student, behavior: IncidentBehavior} $world
     */
    private function makeReport(
        array $world,
        ?Teacher $creator = null,
        bool $expelled = false,
        bool $serious = false,
    ): IncidentReport {
        if ($creator === null) {
            $creator = $this->makeTeacher('default.' . uniqid('', false));
            $this->persist($creator);
        }

        $behavior = $world['behavior'];
        if ($serious) {
            $seriousBeh = (new IncidentBehavior())
                ->setEducationalCentre($world['centre'])
                ->setName('Agresión')
                ->setPosition(1)
                ->setSerious(true)
                ->setActive(true);
            $this->persist($seriousBeh);
            $behavior = $seriousBeh;
        }

        $report = (new IncidentReport())
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass($expelled);
        $report->addBehavior($behavior);

        return $report;
    }

    private function makeTeacher(string $username, bool $admin = false): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername($username)
            ->setAdmin($admin);
    }
}
