<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\CommunicationRepository;
use App\Tests\Integration\RepositoryTestCase;

class CommunicationRepositoryTest extends RepositoryTestCase
{
    private CommunicationRepository $repo;
    private int $nextReportNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CommunicationRepository $repo */
        $repo       = self::getContainer()->get(CommunicationRepository::class);
        $this->repo = $repo;
    }

    // ── createFilteredQuery: visibilidad admin ─────────────────────────────

    public function testGlobalAdminSeesAllCommunicationsOfYear(): void
    {
        $world   = $this->makeWorld();
        $admin   = $this->makeTeacher('admin.sees.all', admin: true);
        $creator = $this->makeTeacher('creator.for.admin');
        $this->persist($admin, $creator);
        $comm = $this->makeReportCommunication($world, $creator);

        $results = $this->repo->createFilteredQuery($world['year'], $admin)->getResult();

        self::assertCount(1, $results);
        self::assertSame($comm->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testCentreAdminSeesAllCommunicationsOfHisCentre(): void
    {
        $world   = $this->makeWorld();
        $cadmin  = $this->makeTeacher('cadmin.sees');
        $creator = $this->makeTeacher('creator.for.cadmin');
        $this->persist($cadmin, $creator);
        $world['centre']->addAdmin($cadmin);
        $this->flush();
        $this->makeReportCommunication($world, $creator);

        $results = $this->repo->createFilteredQuery($world['year'], $cadmin)->getResult();

        self::assertCount(1, $results);
    }

    public function testCommitteeMemberSeesAllCommunicationsOfHisCentre(): void
    {
        $world   = $this->makeWorld();
        $member  = $this->makeTeacher('committee.sees');
        $creator = $this->makeTeacher('creator.for.committee');
        $this->persist($member, $creator);
        $world['centre']->addCommitteeMember($member);
        $this->flush();
        $this->makeSanctionCommunication($world, $creator);

        $results = $this->repo->createFilteredQuery($world['year'], $member)->getResult();

        self::assertCount(1, $results);
    }

    public function testCounselorSeesAllCommunicationsOfHisCentre(): void
    {
        $world     = $this->makeWorld();
        $counselor = $this->makeTeacher('counselor.sees');
        $creator   = $this->makeTeacher('creator.for.counselor');
        $this->persist($counselor, $creator);
        $world['centre']->addCounselor($counselor);
        $this->flush();
        $this->makeReportCommunication($world, $creator);

        $results = $this->repo->createFilteredQuery($world['year'], $counselor)->getResult();

        self::assertCount(1, $results);
    }

    // ── createFilteredQuery: visibilidad no-admin ──────────────────────────

    public function testNonAdminSeesOwnReportCommunication(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('non.admin.own.report');
        $this->persist($teacher);
        $comm = $this->makeReportCommunication($world, $teacher);

        $results = $this->repo->createFilteredQuery($world['year'], $teacher)->getResult();

        self::assertCount(1, $results);
        self::assertSame($comm->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testNonAdminTutorSeesReportCommunicationOfHisGroup(): void
    {
        $world   = $this->makeWorld();
        $tutor   = $this->makeTeacher('non.admin.tutor.report');
        $creator = $this->makeTeacher('creator.for.tutor.report');
        $this->persist($tutor, $creator);
        $world['group']->addTutor($tutor);
        $this->flush();
        $this->makeReportCommunication($world, $creator);

        $results = $this->repo->createFilteredQuery($world['year'], $tutor)->getResult();

        self::assertCount(1, $results);
    }

    public function testNonAdminSeesOwnSanctionCommunicationViaLinkedReport(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('non.admin.own.sanction');
        $this->persist($teacher);
        $comm = $this->makeSanctionCommunication($world, $teacher);

        $results = $this->repo->createFilteredQuery($world['year'], $teacher)->getResult();

        self::assertCount(1, $results);
        self::assertSame($comm->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testNonAdminTutorSeesSanctionCommunicationOfHisGroup(): void
    {
        $world   = $this->makeWorld();
        $tutor   = $this->makeTeacher('non.admin.tutor.sanction');
        $creator = $this->makeTeacher('creator.for.tutor.sanction');
        $this->persist($tutor, $creator);
        $world['group']->addTutor($tutor);
        $this->flush();
        $this->makeSanctionCommunication($world, $creator);

        $results = $this->repo->createFilteredQuery($world['year'], $tutor)->getResult();

        self::assertCount(1, $results);
    }

    public function testNonAdminUnrelatedTeacherSeesNothing(): void
    {
        $world   = $this->makeWorld();
        $other   = $this->makeTeacher('unrelated.teacher.comm');
        $creator = $this->makeTeacher('creator.unrelated.comm');
        $this->persist($other, $creator);
        $this->makeReportCommunication($world, $creator);
        $this->makeSanctionCommunication($world, $creator);

        $results = $this->repo->createFilteredQuery($world['year'], $other)->getResult();

        self::assertCount(0, $results);
    }

    // ── createFilteredQuery: alcance por curso académico ───────────────────

    public function testCreateFilteredQueryIsScopedToTheGivenYear(): void
    {
        $worldA  = $this->makeWorld('A');
        $worldB  = $this->makeWorld('B');
        $admin   = $this->makeTeacher('year.scope.admin', admin: true);
        $this->persist($admin);
        $this->makeReportCommunication($worldA, $admin);

        $results = $this->repo->createFilteredQuery($worldB['year'], $admin)->getResult();

        self::assertCount(0, $results);
    }

    // ── createFilteredQuery: filtros ─────────────────────────────────────────

    public function testCreateFilteredQuerySearchMatchesStudentAndGroupName(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeTeacher('filter.search.comm', admin: true);
        $this->persist($admin);
        $this->makeReportCommunication($world, $admin);

        self::assertCount(1, $this->repo->createFilteredQuery($world['year'], $admin, ['search' => 'garcía'])->getResult());
        self::assertCount(1, $this->repo->createFilteredQuery($world['year'], $admin, ['search' => '1ºA'])->getResult());
        self::assertCount(0, $this->repo->createFilteredQuery($world['year'], $admin, ['search' => 'nadie'])->getResult());
    }

    public function testCreateFilteredQuerySearchMatchesPerformedByTeacherName(): void
    {
        $world   = $this->makeWorld();
        $admin   = $this->makeTeacher('filter.search.admin.comm', admin: true);
        $creator = (new Teacher(new PersonName('Marta', 'Ruiz')))->setUsername('filter.search.creator.comm');
        $this->persist($admin);
        $this->persist($creator);
        $this->makeReportCommunication($world, $creator);

        self::assertCount(1, $this->repo->createFilteredQuery($world['year'], $admin, ['search' => 'ruiz'])->getResult());
        self::assertCount(1, $this->repo->createFilteredQuery($world['year'], $admin, ['search' => 'marta'])->getResult());
        self::assertCount(0, $this->repo->createFilteredQuery($world['year'], $admin, ['search' => 'nadie'])->getResult());
    }

    public function testCreateFilteredQueryFiltersByType(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeTeacher('filter.type.comm', admin: true);
        $this->persist($admin);
        $reportComm   = $this->makeReportCommunication($world, $admin);
        $sanctionComm = $this->makeSanctionCommunication($world, $admin);

        $onlyReports = $this->repo->createFilteredQuery($world['year'], $admin, ['type' => 'report'])->getResult();
        self::assertCount(1, $onlyReports);
        self::assertSame($reportComm->getId()->toRfc4122(), $onlyReports[0]->getId()->toRfc4122());

        $onlySanctions = $this->repo->createFilteredQuery($world['year'], $admin, ['type' => 'sanction'])->getResult();
        self::assertCount(1, $onlySanctions);
        self::assertSame($sanctionComm->getId()->toRfc4122(), $onlySanctions[0]->getId()->toRfc4122());

        self::assertCount(2, $this->repo->createFilteredQuery($world['year'], $admin)->getResult());
    }

    public function testCreateFilteredQueryFiltersByResult(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeTeacher('filter.result.comm', admin: true);
        $this->persist($admin);
        $notified = $this->makeReportCommunication($world, $admin, CommunicationResult::Notified);
        $this->makeReportCommunication($world, $admin, CommunicationResult::NotNotified);

        $results = $this->repo->createFilteredQuery($world['year'], $admin, ['result' => 'notified'])->getResult();

        self::assertCount(1, $results);
        self::assertSame($notified->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testCreateFilteredQueryOrdersByPerformedAtDescending(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeTeacher('order.comm', admin: true);
        $this->persist($admin);
        $older = $this->makeReportCommunication($world, $admin, performedAt: new \DateTimeImmutable('-2 days'));
        $newer = $this->makeReportCommunication($world, $admin, performedAt: new \DateTimeImmutable());

        $results = $this->repo->createFilteredQuery($world['year'], $admin)->getResult();

        self::assertCount(2, $results);
        self::assertSame($newer->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
        self::assertSame($older->getId()->toRfc4122(), $results[1]->getId()->toRfc4122());
    }

    // ── findByIncidentReports ──────────────────────────────────────────────

    public function testFindByIncidentReportsGroupsCommunicationsByReportId(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('find.by.reports.comm');
        $this->persist($teacher);
        $comm = $this->makeReportCommunication($world, $teacher);
        $report = $comm->getIncidentReport();
        self::assertNotNull($report);

        $otherWorld   = $this->makeWorld('other');
        $otherTeacher = $this->makeTeacher('find.by.reports.comm.other');
        $this->persist($otherTeacher);
        $otherReport = (new IncidentReport())
            ->setAcademicYear($otherWorld['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($otherWorld['student'])
            ->setGroup($otherWorld['group'])
            ->setRegisteredBy($otherTeacher)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Sin comunicaciones</p>')
            ->setExpelledFromClass(false);
        $otherReport->addBehavior($otherWorld['behavior']);
        $this->persist($otherReport);

        $map = $this->repo->findByIncidentReports([$report, $otherReport]);

        self::assertCount(1, $map[$report->getId()->toRfc4122()]);
        self::assertSame($comm->getId()->toRfc4122(), $map[$report->getId()->toRfc4122()][0]->getId()->toRfc4122());
        self::assertSame([], $map[$otherReport->getId()->toRfc4122()]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod}
     */
    private function makeWorld(string $suffix = ''): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'c'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE' . $suffix . uniqid('', false));
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
        $method    = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student, $category, $behavior, $method);

        return compact('centre', 'year', 'group', 'student', 'behavior', 'method');
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod} $world
     */
    private function makeReportCommunication(
        array $world,
        Teacher $creator,
        CommunicationResult $result = CommunicationResult::Notified,
        ?\DateTimeImmutable $performedAt = null,
    ): Communication {
        $report = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($world['behavior']);
        $this->persist($report);

        $communication = Communication::forIncidentReport(
            $report,
            $world['method'],
            $creator,
            $performedAt ?? new \DateTimeImmutable(),
            $result,
        );
        $this->persist($communication);
        $report->setNotifiedCommunication($communication);
        $this->flush();

        return $communication;
    }

    /**
     * Creates a sanction linked to a report registered by $creator, with a communication
     * registered against the sanction (non-admin visibility for sanctions goes through the
     * linked report's registrant, not the sanction's own registeredBy).
     *
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod} $world
     */
    private function makeSanctionCommunication(array $world, Teacher $creator): Communication
    {
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setDetails('Detalle de prueba')
            ->setNoMeasureApplied(false);
        $this->persist($sanction);

        $report = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false)
            ->setSanction($sanction);
        $report->addBehavior($world['behavior']);
        $sanction->getReports()->add($report);
        $this->persist($report);

        $communication = Communication::forSanction($sanction, $world['method'], $creator, new \DateTimeImmutable(), CommunicationResult::Notified);
        $this->persist($communication);
        $sanction->setNotifiedCommunication($communication);
        $this->flush();

        return $communication;
    }

    private function makeTeacher(string $username, bool $admin = false): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername($username)
            ->setAdmin($admin);
    }
}
