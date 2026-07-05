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
    private int $nextReportNumber = 0;

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

    // ── Visibilidad: comisión de convivencia / orientador ────────────────────

    public function testCommitteeMemberSeesAllReportsOfHisCentre(): void
    {
        $world    = $this->makeWorld();
        $member   = $this->makeTeacher('committee.vis');
        $report   = $this->makeReport($world);
        $this->persist($member, $report);
        $world['centre']->addCommitteeMember($member);
        $this->flush();

        $results = $this->repo->createFilteredQuery($world['centre'], $member)->getResult();

        self::assertCount(1, $results);
    }

    public function testCounselorSeesAllReportsOfHisCentre(): void
    {
        $world     = $this->makeWorld();
        $counselor = $this->makeTeacher('counselor.vis');
        $report    = $this->makeReport($world);
        $this->persist($counselor, $report);
        $world['centre']->addCounselor($counselor);
        $this->flush();

        $results = $this->repo->createFilteredQuery($world['centre'], $counselor)->getResult();

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

    public function testSearchStudentGroupPairsFindsStudentByGroupName(): void
    {
        $world = $this->makeWorld();
        $world['group']->addStudent($world['student']);
        $this->flush();

        $pairs = $this->repo->searchStudentGroupPairs($world['centre'], '1ºA', 10);

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

    // ── findPendingNotification ───────────────────────────────────────────────

    public function testFindPendingNotificationExcludesNotifiedReports(): void
    {
        $world    = $this->makeWorld('pn1');
        $admin    = $this->makeTeacher('admin.pending', admin: true);
        $pending  = $this->makeReport($world, creator: $admin);
        $notified = $this->makeReport($world, creator: $admin);
        $this->persist($admin, $pending, $notified);
        $this->notify($notified, $world, $admin);

        $results = $this->repo->findPendingNotification($world['centre'], $admin);

        self::assertCount(1, $results);
        self::assertSame($pending->getId(), $results[0]->getId());
    }

    public function testFindPendingNotificationRestrictsVisibilityForRegularTeacher(): void
    {
        $world = $this->makeWorld('pn2');
        $t1    = $this->makeTeacher('t1.pending');
        $t2    = $this->makeTeacher('t2.pending');
        $rOwn  = $this->makeReport($world, creator: $t1);
        $rOth  = $this->makeReport($world, creator: $t2);
        $this->persist($t1, $t2, $rOwn, $rOth);

        $results = $this->repo->findPendingNotification($world['centre'], $t1);

        self::assertCount(1, $results);
        self::assertSame($rOwn->getId(), $results[0]->getId());
    }

    public function testFindPendingNotificationIncludesGroupTutorReports(): void
    {
        $world  = $this->makeWorld('pn3');
        $tutor  = $this->makeTeacher('tutor.pending');
        $other  = $this->makeTeacher('other.pending');
        $rOwn   = $this->makeReport($world, creator: $tutor);
        $rGroup = $this->makeReport($world, creator: $other);
        $this->persist($tutor, $other, $rOwn, $rGroup);
        $world['group']->addTutor($tutor);
        $this->flush();

        $results = $this->repo->findPendingNotification($world['centre'], $tutor);

        self::assertCount(2, $results);
    }

    // ── createPendingQuery ────────────────────────────────────────────────────

    public function testCreatePendingQueryMatchesFindPendingNotification(): void
    {
        $world  = $this->makeWorld('cpq1');
        $tutor  = $this->makeTeacher('tutor.cpq');
        $other  = $this->makeTeacher('other.cpq');
        $rOwn   = $this->makeReport($world, creator: $tutor);
        $rGroup = $this->makeReport($world, creator: $other);
        $this->persist($tutor, $other, $rOwn, $rGroup);
        $world['group']->addTutor($tutor);
        $this->flush();

        $expected = $this->repo->findPendingNotification($world['centre'], $tutor);
        $actual   = $this->repo->createPendingQuery($world['centre'], $tutor)->getResult();

        self::assertCount(2, $actual);
        self::assertCount(count($expected), $actual);
    }

    // ── createNotifiableQuery ─────────────────────────────────────────────────

    public function testCreateNotifiableQueryAdminAlwaysAllowed(): void
    {
        $world  = $this->makeWorld('cnq1');
        $admin  = $this->makeTeacher('admin.cnq', admin: true);
        $other  = $this->makeTeacher('other.cnq1');
        $report = $this->makeReport($world, creator: $other);
        $this->persist($admin, $other, $report);

        $results = $this->repo->createNotifiableQuery($world['centre'], $admin, 'report_teacher')->getResult();

        self::assertCount(1, $results);
    }

    public function testCreateNotifiableQueryReportTeacherSettingRestrictsToRegistrant(): void
    {
        $world  = $this->makeWorld('cnq2');
        $tutor  = $this->makeTeacher('tutor.cnq2');
        $other  = $this->makeTeacher('other.cnq2');
        $rOwn   = $this->makeReport($world, creator: $tutor);
        $rGroup = $this->makeReport($world, creator: $other);
        $this->persist($tutor, $other, $rOwn, $rGroup);
        $world['group']->addTutor($tutor);
        $this->flush();

        $results = $this->repo->createNotifiableQuery($world['centre'], $tutor, 'report_teacher')->getResult();

        self::assertCount(1, $results);
        self::assertSame($rOwn->getId(), $results[0]->getId());
    }

    public function testCreateNotifiableQueryGroupTutorSettingRestrictsToTutors(): void
    {
        $world  = $this->makeWorld('cnq3');
        $tutor  = $this->makeTeacher('tutor.cnq3');
        $other  = $this->makeTeacher('other.cnq3');
        $rOwn   = $this->makeReport($world, creator: $tutor);
        $rGroup = $this->makeReport($world, creator: $other);
        $this->persist($tutor, $other, $rOwn, $rGroup);
        $world['group']->addTutor($tutor);
        $this->flush();

        $results = $this->repo->createNotifiableQuery($world['centre'], $other, 'group_tutor')->getResult();

        self::assertCount(0, $results);
    }

    public function testCreateNotifiableQueryBothSettingAllowsRegistrantOrTutor(): void
    {
        $world  = $this->makeWorld('cnq4');
        $tutor  = $this->makeTeacher('tutor.cnq4');
        $other  = $this->makeTeacher('other.cnq4');
        $rOwn   = $this->makeReport($world, creator: $tutor);
        $rGroup = $this->makeReport($world, creator: $other);
        $this->persist($tutor, $other, $rOwn, $rGroup);
        $world['group']->addTutor($tutor);
        $this->flush();

        $results = $this->repo->createNotifiableQuery($world['centre'], $tutor, 'both')->getResult();

        self::assertCount(2, $results);
    }

    public function testCreateNotifiableQueryCommitteeMemberIsNotAutomaticallyAllowed(): void
    {
        $world   = $this->makeWorld('cnq5');
        $member  = $this->makeTeacher('committee.cnq5');
        $other   = $this->makeTeacher('other.cnq5');
        $report  = $this->makeReport($world, creator: $other);
        $this->persist($member, $other, $report);
        $world['centre']->addCommitteeMember($member);
        $this->flush();

        $results = $this->repo->createNotifiableQuery($world['centre'], $member, 'both')->getResult();

        self::assertCount(0, $results);
    }

    public function testCreateNotifiableQueryExcludesAlreadyNotifiedReports(): void
    {
        $world = $this->makeWorld('cnq6');
        $admin = $this->makeTeacher('admin.cnq6', admin: true);
        $pending  = $this->makeReport($world, creator: $admin);
        $notified = $this->makeReport($world, creator: $admin);
        $this->persist($admin, $pending, $notified);
        $this->notify($notified, $world, $admin);

        $results = $this->repo->createNotifiableQuery($world['centre'], $admin, 'both')->getResult();

        self::assertCount(1, $results);
        self::assertSame($pending->getId(), $results[0]->getId());
    }

    public function testCreateNotifiableQueryFiltersByStudent(): void
    {
        $world    = $this->makeWorld('cnq7');
        $admin    = $this->makeTeacher('admin.cnq7', admin: true);
        $report   = $this->makeReport($world, creator: $admin);
        $otherStudent = new \App\Entity\Student(new PersonName('Bea', 'López'));
        $otherStudent->setStudentId('NIE-cnq7-' . uniqid('', false));
        $this->persist($admin, $report, $otherStudent);

        $otherReport = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($otherStudent)
            ->setGroup($world['group'])
            ->setRegisteredBy($admin)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Otro</p>')
            ->setExpelledFromClass(false);
        $otherReport->addBehavior($world['behavior']);
        $this->persist($otherReport);

        $results = $this->repo->createNotifiableQuery($world['centre'], $admin, 'both', $world['student'])->getResult();

        self::assertCount(1, $results);
        self::assertSame($report->getId(), $results[0]->getId());
    }

    // ── findNotifiableSummaryByStudent ────────────────────────────────────────

    public function testFindNotifiableSummaryByStudentGroupsAndCountsPerStudent(): void
    {
        $world  = $this->makeWorld('sum1');
        $admin  = $this->makeTeacher('admin.sum1', admin: true);
        $r1     = $this->makeReport($world, creator: $admin);
        $r2     = $this->makeReport($world, creator: $admin);
        $this->persist($admin, $r1, $r2);

        $summary = $this->repo->findNotifiableSummaryByStudent($world['centre'], $admin, 'both');

        self::assertCount(1, $summary);
        self::assertSame($world['student']->getId(), $summary[0]['student']->getId());
        self::assertSame(2, $summary[0]['count']);
    }

    public function testFindNotifiableSummaryByStudentOrdersByCountDescending(): void
    {
        $world    = $this->makeWorld('sum2');
        $admin    = $this->makeTeacher('admin.sum2', admin: true);
        $studentB = new \App\Entity\Student(new PersonName('Bea', 'López'));
        $studentB->setStudentId('NIE-sum2-' . uniqid('', false));
        $this->persist($admin, $studentB);

        // Student A (world's default, "García") gets 1 report
        $this->persist($this->makeReport($world, creator: $admin));

        // Student B ("López") gets 2 reports
        for ($i = 0; $i < 2; $i++) {
            $r = (new IncidentReport())
                ->setAcademicYear($world['year'])
                ->setNumber(++$this->nextReportNumber)
                ->setStudent($studentB)
                ->setGroup($world['group'])
                ->setRegisteredBy($admin)
                ->setOccurredAt(new \DateTimeImmutable())
                ->setDescription('<p>Test</p>')
                ->setExpelledFromClass(false);
            $r->addBehavior($world['behavior']);
            $this->persist($r);
        }

        $summary = $this->repo->findNotifiableSummaryByStudent($world['centre'], $admin, 'both');

        self::assertCount(2, $summary);
        self::assertSame('López', $summary[0]['student']->getName()->getLastName());
        self::assertSame(2, $summary[0]['count']);
        self::assertSame('García', $summary[1]['student']->getName()->getLastName());
        self::assertSame(1, $summary[1]['count']);
    }

    public function testFindNotifiableSummaryByStudentRestrictsToNotifierSetting(): void
    {
        $world  = $this->makeWorld('sum3');
        $tutor  = $this->makeTeacher('tutor.sum3');
        $other  = $this->makeTeacher('other.sum3');
        $report = $this->makeReport($world, creator: $other);
        $this->persist($tutor, $other, $report);

        $summary = $this->repo->findNotifiableSummaryByStudent($world['centre'], $tutor, 'report_teacher');

        self::assertCount(0, $summary);
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
        $category  = (new \App\Entity\IncidentBehaviorCategory())
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

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $programme, $level, $group, $student, $category, $behavior);

        return compact('centre', 'year', 'group', 'student', 'behavior');
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior} $world
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
            $seriousCat = (new \App\Entity\IncidentBehaviorCategory())
                ->setEducationalCentre($world['centre'])
                ->setName('Graves')
                ->setSerious(true)
                ->setPosition(1);
            $seriousBeh = (new IncidentBehavior())
                ->setEducationalCentre($world['centre'])
                ->setCategory($seriousCat)
                ->setName('Agresión')
                ->setPosition(0)
                ->setActive(true);
            $this->persist($seriousCat, $seriousBeh);
            $behavior = $seriousBeh;
        }

        $report = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
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

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior} $world
     */
    private function notify(IncidentReport $report, array $world, Teacher $teacher): void
    {
        $method = (new CommunicationMethod())
            ->setEducationalCentre($world['centre'])
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($method);

        $communication = Communication::forIncidentReport($report, $method, $teacher, new \DateTimeImmutable(), CommunicationResult::Notified);
        $this->persist($communication);

        $report->setNotifiedCommunication($communication);
        $this->flush();
    }
}
