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
use App\Entity\Course;
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

        $results = $this->repo->createFilteredQuery($world['centre'], $admin, $world['year'])->getResult();

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

        $results = $this->repo->createFilteredQuery($world['centre'], $cadmin, $world['year'])->getResult();

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

        $results = $this->repo->createFilteredQuery($world['centre'], $member, $world['year'])->getResult();

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

        $results = $this->repo->createFilteredQuery($world['centre'], $counselor, $world['year'])->getResult();

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
        $results = $this->repo->createFilteredQuery($worldB['centre'], $cadmin, $worldB['year'])->getResult();

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

        $results = $this->repo->createFilteredQuery($world['centre'], $t1, $world['year'])->getResult();

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

        $results = $this->repo->createFilteredQuery($world['centre'], $tutor, $world['year'])->getResult();

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
            $world['year'],
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
            $world['year'],
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
            $world['year'],
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

        $count = $this->repo->countRecentByCentre($world['centre'], $t1, $world['year'], 30);

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

        $count = $this->repo->countRecentByCentre($world['centre'], $admin, $world['year'], 30);

        self::assertSame(2, $count);
    }

    public function testCountRecentByCentreRestrictsToGivenAcademicYear(): void
    {
        $worldA = $this->makeWorld();
        $worldB = $this->makeOtherYearInSameCentre($worldA);
        $admin  = $this->makeTeacher('admin.count.year', admin: true);
        $rA     = $this->makeReport($worldA, creator: $admin);
        $rB     = $this->makeReport($worldB, creator: $admin);
        $this->persist($admin, $rA, $rB);

        self::assertSame(1, $this->repo->countRecentByCentre($worldA['centre'], $admin, $worldA['year'], 30));
        self::assertSame(1, $this->repo->countRecentByCentre($worldA['centre'], $admin, $worldB['year'], 30));
    }

    // ── countPendingPrescriptionForViewer ──────────────────────────────────────

    public function testCountPendingPrescriptionForViewerCountsOnlyReportsAtOrBeforeCutoff(): void
    {
        $world  = $this->makeWorld();
        $admin  = $this->makeTeacher('admin.prescription', admin: true);
        $old    = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-10 days'));
        $recent = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-1 day'));
        $this->persist($admin, $old, $recent);

        $count = $this->repo->countPendingPrescriptionForViewer(
            $world['centre'],
            $admin,
            $world['year'],
            new \DateTimeImmutable('-5 days'),
        );

        self::assertSame(1, $count);
    }

    public function testCountPendingPrescriptionForViewerExcludesNotifiedReports(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeTeacher('admin.prescription.notified', admin: true);
        $report = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-10 days'));
        $this->persist($admin, $report);
        $this->notify($report, $world, $admin);

        $count = $this->repo->countPendingPrescriptionForViewer(
            $world['centre'],
            $admin,
            $world['year'],
            new \DateTimeImmutable('-5 days'),
        );

        self::assertSame(0, $count);
    }

    public function testCountPendingPrescriptionForViewerExcludesAlreadyPrescribedReports(): void
    {
        $world  = $this->makeWorld();
        $admin  = $this->makeTeacher('admin.prescription.prescribed', admin: true);
        $report = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-10 days'));
        $report->setPrescribedAt(new \DateTimeImmutable());
        $this->persist($admin, $report);

        $count = $this->repo->countPendingPrescriptionForViewer(
            $world['centre'],
            $admin,
            $world['year'],
            new \DateTimeImmutable('-5 days'),
        );

        self::assertSame(0, $count);
    }

    public function testCountPendingPrescriptionForViewerRestrictsToOwnReportsForRegularTeacher(): void
    {
        $world = $this->makeWorld();
        $t1    = $this->makeTeacher('t1.prescription');
        $t2    = $this->makeTeacher('t2.prescription');
        $r1    = $this->makeReport($world, creator: $t1, occurredAt: new \DateTimeImmutable('-10 days'));
        $r2    = $this->makeReport($world, creator: $t2, occurredAt: new \DateTimeImmutable('-10 days'));
        $this->persist($t1, $t2, $r1, $r2);

        $count = $this->repo->countPendingPrescriptionForViewer(
            $world['centre'],
            $t1,
            $world['year'],
            new \DateTimeImmutable('-5 days'),
        );

        self::assertSame(1, $count);
    }

    // ── createFilteredQuery: aislamiento por curso académico ──────────────────

    public function testCreateFilteredQueryRestrictsToGivenAcademicYear(): void
    {
        $worldA = $this->makeWorld();
        $worldB = $this->makeOtherYearInSameCentre($worldA);
        $admin  = $this->makeTeacher('admin.year.isolation', admin: true);
        $rA     = $this->makeReport($worldA, creator: $admin);
        $rB     = $this->makeReport($worldB, creator: $admin);
        $this->persist($admin, $rA, $rB);

        $resultsA = $this->repo->createFilteredQuery($worldA['centre'], $admin, $worldA['year'])->getResult();
        $resultsB = $this->repo->createFilteredQuery($worldA['centre'], $admin, $worldB['year'])->getResult();

        self::assertCount(1, $resultsA);
        self::assertSame($rA->getId(), $resultsA[0]->getId());
        self::assertCount(1, $resultsB);
        self::assertSame($rB->getId(), $resultsB[0]->getId());
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

        $results = $this->repo->findPendingNotification($world['centre'], $admin, $world['year']);

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

        $results = $this->repo->findPendingNotification($world['centre'], $t1, $world['year']);

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

        $results = $this->repo->findPendingNotification($world['centre'], $tutor, $world['year']);

        self::assertCount(2, $results);
    }

    public function testFindPendingNotificationRestrictsToGivenAcademicYear(): void
    {
        $worldA = $this->makeWorld('pn4');
        $worldB = $this->makeOtherYearInSameCentre($worldA, 'pn4b');
        $admin  = $this->makeTeacher('admin.pending.year', admin: true);
        $rA     = $this->makeReport($worldA, creator: $admin);
        $rB     = $this->makeReport($worldB, creator: $admin);
        $this->persist($admin, $rA, $rB);

        $resultsA = $this->repo->findPendingNotification($worldA['centre'], $admin, $worldA['year']);

        self::assertCount(1, $resultsA);
        self::assertSame($rA->getId(), $resultsA[0]->getId());
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

        $expected = $this->repo->findPendingNotification($world['centre'], $tutor, $world['year']);
        $actual   = $this->repo->createPendingQuery($world['centre'], $tutor, $world['year'])->getResult();

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

        $results = $this->repo->createNotifiableQuery($world['centre'], $admin, 'report_teacher', $world['year'])->getResult();

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

        $results = $this->repo->createNotifiableQuery($world['centre'], $tutor, 'report_teacher', $world['year'])->getResult();

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

        $results = $this->repo->createNotifiableQuery($world['centre'], $other, 'group_tutor', $world['year'])->getResult();

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

        $results = $this->repo->createNotifiableQuery($world['centre'], $tutor, 'both', $world['year'])->getResult();

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

        $results = $this->repo->createNotifiableQuery($world['centre'], $member, 'both', $world['year'])->getResult();

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

        $results = $this->repo->createNotifiableQuery($world['centre'], $admin, 'both', $world['year'])->getResult();

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

        $results = $this->repo->createNotifiableQuery($world['centre'], $admin, 'both', $world['year'], $world['student'])->getResult();

        self::assertCount(1, $results);
        self::assertSame($report->getId(), $results[0]->getId());
    }

    public function testCreateNotifiableQueryRestrictsToGivenAcademicYear(): void
    {
        $worldA = $this->makeWorld('cnq8');
        $worldB = $this->makeOtherYearInSameCentre($worldA, 'cnq8b');
        $admin  = $this->makeTeacher('admin.cnq8', admin: true);
        $rA     = $this->makeReport($worldA, creator: $admin);
        $rB     = $this->makeReport($worldB, creator: $admin);
        $this->persist($admin, $rA, $rB);

        $resultsA = $this->repo->createNotifiableQuery($worldA['centre'], $admin, 'both', $worldA['year'])->getResult();

        self::assertCount(1, $resultsA);
        self::assertSame($rA->getId(), $resultsA[0]->getId());
    }

    // ── findNotifiableSummaryByStudent ────────────────────────────────────────

    public function testFindNotifiableSummaryByStudentGroupsAndCountsPerStudent(): void
    {
        $world  = $this->makeWorld('sum1');
        $admin  = $this->makeTeacher('admin.sum1', admin: true);
        $r1     = $this->makeReport($world, creator: $admin);
        $r2     = $this->makeReport($world, creator: $admin);
        $this->persist($admin, $r1, $r2);

        $summary = $this->repo->findNotifiableSummaryByStudent($world['centre'], $admin, 'both', $world['year']);

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

        $summary = $this->repo->findNotifiableSummaryByStudent($world['centre'], $admin, 'both', $world['year']);

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

        $summary = $this->repo->findNotifiableSummaryByStudent($world['centre'], $tutor, 'report_teacher', $world['year']);

        self::assertCount(0, $summary);
    }

    public function testFindNotifiableSummaryByStudentRestrictsToGivenAcademicYear(): void
    {
        $worldA = $this->makeWorld('sum4');
        $worldB = $this->makeOtherYearInSameCentre($worldA, 'sum4b');
        $admin  = $this->makeTeacher('admin.sum4', admin: true);
        $rA     = $this->makeReport($worldA, creator: $admin);
        $rB     = $this->makeReport($worldB, creator: $admin);
        $this->persist($admin, $rA, $rB);

        $summary = $this->repo->findNotifiableSummaryByStudent($worldA['centre'], $admin, 'both', $worldA['year']);

        self::assertCount(1, $summary);
        self::assertSame($worldA['student']->getId(), $summary[0]['student']->getId());
        self::assertSame(1, $summary[0]['count']);
    }

    // ── findGroupsWithReports ─────────────────────────────────────────────────

    public function testFindGroupsWithReportsRestrictsToGivenAcademicYear(): void
    {
        $worldA = $this->makeWorld('fgwr1');
        $worldB = $this->makeOtherYearInSameCentre($worldA, 'fgwr1b');
        $admin  = $this->makeTeacher('admin.fgwr1', admin: true);
        $rA     = $this->makeReport($worldA, creator: $admin);
        $rB     = $this->makeReport($worldB, creator: $admin);
        $this->persist($admin, $rA, $rB);

        $groupsA = $this->repo->findGroupsWithReports($worldA['centre'], $admin, $worldA['year']);
        $groupsB = $this->repo->findGroupsWithReports($worldA['centre'], $admin, $worldB['year']);

        self::assertCount(1, $groupsA);
        self::assertSame($worldA['group']->getId(), $groupsA[0]->getId());
        self::assertCount(1, $groupsB);
        self::assertSame($worldB['group']->getId(), $groupsB[0]->getId());
    }

    // ── findForGroupStats ─────────────────────────────────────────────────────

    public function testFindForGroupStatsReturnsReportsWithinRange(): void
    {
        $world   = $this->makeWorld('fgs1');
        $admin   = $this->makeTeacher('admin.fgs1', admin: true);
        $inRange = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('2026-03-10'));
        $before  = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('2026-01-01'));
        $after   = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('2026-06-01'));
        $this->persist($admin, $inRange, $before, $after);

        $results = $this->repo->findForGroupStats(
            $world['centre'],
            $world['year'],
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-04-01'),
        );

        self::assertCount(1, $results);
        self::assertSame($inRange->getId(), $results[0]->getId());
    }

    public function testFindForGroupStatsRestrictsToGivenAcademicYear(): void
    {
        $worldA = $this->makeWorld('fgs2');
        $worldB = $this->makeOtherYearInSameCentre($worldA, 'fgs2b');
        $admin  = $this->makeTeacher('admin.fgs2', admin: true);
        $rA     = $this->makeReport($worldA, creator: $admin, occurredAt: new \DateTimeImmutable('2026-03-10'));
        $rB     = $this->makeReport($worldB, creator: $admin, occurredAt: new \DateTimeImmutable('2026-03-10'));
        $this->persist($admin, $rA, $rB);

        $results = $this->repo->findForGroupStats(
            $worldA['centre'],
            $worldA['year'],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertCount(1, $results);
        self::assertSame($rA->getId(), $results[0]->getId());
    }

    public function testFindForGroupStatsIsScopedByCentre(): void
    {
        $worldA  = $this->makeWorld('fgs3a');
        $worldB  = $this->makeWorld('fgs3b');
        $admin   = $this->makeTeacher('admin.fgs3', admin: true);
        $reportA = $this->makeReport($worldA, creator: $admin, occurredAt: new \DateTimeImmutable('2026-03-10'));
        $reportB = $this->makeReport($worldB, creator: $admin, occurredAt: new \DateTimeImmutable('2026-03-10'));
        $this->persist($admin, $reportA, $reportB);

        $results = $this->repo->findForGroupStats(
            $worldA['centre'],
            $worldA['year'],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31'),
        );

        self::assertCount(1, $results);
        self::assertSame($reportA->getId(), $results[0]->getId());
    }

    // ── findEligibleForAutoPrescription ──────────────────────────────────────

    public function testFindEligibleForAutoPrescriptionExcludesNotifiedReports(): void
    {
        $world    = $this->makeWorld('eap1');
        $admin    = $this->makeTeacher('admin.eap1', admin: true);
        $old      = new \DateTimeImmutable('-20 days');
        $eligible = $this->makeReport($world, creator: $admin, occurredAt: $old);
        $notified = $this->makeReport($world, creator: $admin, occurredAt: $old);
        $this->persist($admin, $eligible, $notified);
        $this->notify($notified, $world, $admin);

        $results = $this->repo->findEligibleForAutoPrescription($world['centre'], new \DateTimeImmutable('-14 days'));

        self::assertCount(1, $results);
        self::assertSame($eligible->getId(), $results[0]->getId());
    }

    public function testFindEligibleForAutoPrescriptionExcludesAlreadyPrescribedReports(): void
    {
        $world       = $this->makeWorld('eap2');
        $admin       = $this->makeTeacher('admin.eap2', admin: true);
        $old         = new \DateTimeImmutable('-20 days');
        $eligible    = $this->makeReport($world, creator: $admin, occurredAt: $old);
        $prescribed  = $this->makeReport($world, creator: $admin, occurredAt: $old);
        $this->persist($admin, $eligible, $prescribed);
        $prescribed->setPrescribedAt(new \DateTimeImmutable());
        $this->flush();

        $results = $this->repo->findEligibleForAutoPrescription($world['centre'], new \DateTimeImmutable('-14 days'));

        self::assertCount(1, $results);
        self::assertSame($eligible->getId(), $results[0]->getId());
    }

    public function testFindEligibleForAutoPrescriptionExcludesReportsOccurredAfterCutoff(): void
    {
        $world  = $this->makeWorld('eap3');
        $admin  = $this->makeTeacher('admin.eap3', admin: true);
        $old    = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-20 days'));
        $recent = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-1 day'));
        $this->persist($admin, $old, $recent);

        $results = $this->repo->findEligibleForAutoPrescription($world['centre'], new \DateTimeImmutable('-14 days'));

        self::assertCount(1, $results);
        self::assertSame($old->getId(), $results[0]->getId());
    }

    public function testFindEligibleForAutoPrescriptionIsScopedByCentre(): void
    {
        $worldA = $this->makeWorld('eap4a');
        $worldB = $this->makeWorld('eap4b');
        $admin  = $this->makeTeacher('admin.eap4', admin: true);
        $old    = new \DateTimeImmutable('-20 days');
        $reportA = $this->makeReport($worldA, creator: $admin, occurredAt: $old);
        $reportB = $this->makeReport($worldB, creator: $admin, occurredAt: $old);
        $this->persist($admin, $reportA, $reportB);

        $results = $this->repo->findEligibleForAutoPrescription($worldA['centre'], new \DateTimeImmutable('-14 days'));

        self::assertCount(1, $results);
        self::assertSame($reportA->getId(), $results[0]->getId());
    }

    // ── findPendingPrescription ───────────────────────────────────────────────

    public function testFindPendingPrescriptionExcludesNotifiedReports(): void
    {
        $world    = $this->makeWorld('fpp1');
        $admin    = $this->makeTeacher('admin.fpp1', admin: true);
        $pending  = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-3 days'));
        $notified = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-3 days'));
        $this->persist($admin, $pending, $notified);
        $this->notify($notified, $world, $admin);

        $results = $this->repo->findPendingPrescription($world['centre']);

        self::assertCount(1, $results);
        self::assertSame($pending->getId(), $results[0]->getId());
    }

    public function testFindPendingPrescriptionExcludesAlreadyPrescribedReports(): void
    {
        $world      = $this->makeWorld('fpp2');
        $admin      = $this->makeTeacher('admin.fpp2', admin: true);
        $pending    = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-3 days'));
        $prescribed = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-3 days'));
        $this->persist($admin, $pending, $prescribed);
        $prescribed->setPrescribedAt(new \DateTimeImmutable());
        $this->flush();

        $results = $this->repo->findPendingPrescription($world['centre']);

        self::assertCount(1, $results);
        self::assertSame($pending->getId(), $results[0]->getId());
    }

    public function testFindPendingPrescriptionIncludesRecentReports(): void
    {
        $world  = $this->makeWorld('fpp3');
        $admin  = $this->makeTeacher('admin.fpp3', admin: true);
        $recent = $this->makeReport($world, creator: $admin, occurredAt: new \DateTimeImmutable('-1 day'));
        $this->persist($admin, $recent);

        $results = $this->repo->findPendingPrescription($world['centre']);

        self::assertCount(1, $results);
        self::assertSame($recent->getId(), $results[0]->getId());
    }

    public function testFindPendingPrescriptionIsScopedByCentre(): void
    {
        $worldA  = $this->makeWorld('fpp4a');
        $worldB  = $this->makeWorld('fpp4b');
        $admin   = $this->makeTeacher('admin.fpp4', admin: true);
        $reportA = $this->makeReport($worldA, creator: $admin, occurredAt: new \DateTimeImmutable('-3 days'));
        $reportB = $this->makeReport($worldB, creator: $admin, occurredAt: new \DateTimeImmutable('-3 days'));
        $this->persist($admin, $reportA, $reportB);

        $results = $this->repo->findPendingPrescription($worldA['centre']);

        self::assertCount(1, $results);
        self::assertSame($reportA->getId(), $results[0]->getId());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior}
     */
    private function makeWorld(string $suffix = ''): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'x'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
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
        $this->persist($centre, $year, $course, $group, $student, $category, $behavior);

        return compact('centre', 'year', 'group', 'student', 'behavior');
    }

    /**
     * Builds a second academic year for the SAME centre as $world, with its own
     * programme/group/student, to test that per-year listings are sealed off
     * from other years of the same centre (and not just from other centres).
     *
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior} $world
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior}
     */
    private function makeOtherYearInSameCentre(array $world, string $suffix = ''): array
    {
        $centre    = $world['centre'];
        $year      = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW-Y2' . $suffix)->setAcademicYear($year);
        $group     = (new Group())->setName('1ºB' . $suffix)->setCourse($course);
        $student   = (new Student(new PersonName('Bea', 'Ruiz')))->setStudentId('NIE-Y2-' . $suffix . uniqid('', false));

        $this->persist($year, $course, $group, $student);

        return ['centre' => $centre, 'year' => $year, 'group' => $group, 'student' => $student, 'behavior' => $world['behavior']];
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior} $world
     */
    private function makeReport(
        array $world,
        ?Teacher $creator = null,
        bool $expelled = false,
        bool $serious = false,
        ?\DateTimeImmutable $occurredAt = null,
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
            ->setOccurredAt($occurredAt ?? new \DateTimeImmutable())
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
