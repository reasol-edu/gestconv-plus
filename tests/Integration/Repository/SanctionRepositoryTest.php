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
use App\Repository\SanctionRepository;
use App\Tests\Integration\RepositoryTestCase;

class SanctionRepositoryTest extends RepositoryTestCase
{
    private SanctionRepository $repo;
    private int $nextReportNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SanctionRepository $repo */
        $repo       = self::getContainer()->get(SanctionRepository::class);
        $this->repo = $repo;
    }

    // ── findById ────────────────────────────────────────────────────────────

    public function testFindByIdReturnsKnownSanction(): void
    {
        $world    = $this->makeWorld();
        $creator  = $this->makeTeacher('findbyid.creator');
        $this->persist($creator);
        $sanction = $this->makeSanction($world, $creator);

        $found = $this->repo->findById($sanction->getId()->toRfc4122());

        self::assertNotNull($found);
        self::assertSame($sanction->getId()->toRfc4122(), $found->getId()->toRfc4122());
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $found = $this->repo->findById('00000000-0000-0000-0000-000000000000');

        self::assertNull($found);
    }

    // ── createFilteredQuery: visibilidad admin ─────────────────────────────

    public function testGlobalAdminSeesAllSanctionsOfCentre(): void
    {
        $world    = $this->makeWorld();
        $admin    = $this->makeTeacher('admin.sees.all', admin: true);
        $creator  = $this->makeTeacher('creator.for.admin');
        $this->persist($admin, $creator);
        $sanction = $this->makeSanctionWithReport($world, $creator);

        $results = $this->repo->createFilteredQuery($world['centre'], $admin, $world['year'])->getResult();

        self::assertCount(1, $results);
        self::assertSame($sanction->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testCentreAdminSeesAllSanctionsOfHisCentre(): void
    {
        $world   = $this->makeWorld();
        $cadmin  = $this->makeTeacher('cadmin.sees');
        $creator = $this->makeTeacher('creator.for.cadmin');
        $this->persist($cadmin, $creator);
        $world['centre']->addAdmin($cadmin);
        $this->flush();
        $sanction = $this->makeSanctionWithReport($world, $creator);

        $results = $this->repo->createFilteredQuery($world['centre'], $cadmin, $world['year'])->getResult();

        self::assertCount(1, $results);
        self::assertSame($sanction->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testCommitteeMemberSeesAllSanctionsOfHisCentre(): void
    {
        $world   = $this->makeWorld();
        $member  = $this->makeTeacher('committee.sees');
        $creator = $this->makeTeacher('creator.for.committee');
        $this->persist($member, $creator);
        $world['centre']->addCommitteeMember($member);
        $this->flush();
        $sanction = $this->makeSanctionWithReport($world, $creator);

        $results = $this->repo->createFilteredQuery($world['centre'], $member, $world['year'])->getResult();

        self::assertCount(1, $results);
        self::assertSame($sanction->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testCounselorSeesAllSanctionsOfHisCentre(): void
    {
        $world     = $this->makeWorld();
        $counselor = $this->makeTeacher('counselor.sees');
        $creator   = $this->makeTeacher('creator.for.counselor');
        $this->persist($counselor, $creator);
        $world['centre']->addCounselor($counselor);
        $this->flush();
        $sanction = $this->makeSanctionWithReport($world, $creator);

        $results = $this->repo->createFilteredQuery($world['centre'], $counselor, $world['year'])->getResult();

        self::assertCount(1, $results);
        self::assertSame($sanction->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testAdminDoesNotSeeSanctionsOfAnotherCentre(): void
    {
        $worldA  = $this->makeWorld('A');
        $worldB  = $this->makeWorld('B');
        $cadmin  = $this->makeTeacher('cadmin.other.centre');
        $creator = $this->makeTeacher('creator.other.centre');
        $this->persist($cadmin, $creator);
        $worldA['centre']->addAdmin($cadmin);
        $this->flush();
        $this->makeSanctionWithReport($worldA, $creator);

        $results = $this->repo->createFilteredQuery($worldB['centre'], $cadmin, $worldB['year'])->getResult();

        self::assertCount(0, $results);
    }

    // ── createFilteredQuery: visibilidad no-admin ──────────────────────────

    public function testNonAdminSeesOwnReportSanction(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('non.admin.own');
        $this->persist($teacher);
        $sanction = $this->makeSanctionWithReport($world, $teacher);

        $results = $this->repo->createFilteredQuery($world['centre'], $teacher, $world['year'])->getResult();

        self::assertCount(1, $results);
        self::assertSame($sanction->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testNonAdminTutorSeesSanctionsOfHisGroup(): void
    {
        $world   = $this->makeWorld();
        $tutor   = $this->makeTeacher('non.admin.tutor');
        $creator = $this->makeTeacher('creator.for.tutor');
        $this->persist($tutor, $creator);
        $world['group']->addTutor($tutor);
        $this->flush();
        $sanction = $this->makeSanctionWithReport($world, $creator);

        $results = $this->repo->createFilteredQuery($world['centre'], $tutor, $world['year'])->getResult();

        self::assertCount(1, $results);
        self::assertSame($sanction->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testNonAdminUnrelatedTeacherSeesNothing(): void
    {
        $world   = $this->makeWorld();
        $other   = $this->makeTeacher('unrelated.teacher');
        $creator = $this->makeTeacher('creator.unrelated');
        $this->persist($other, $creator);
        $this->makeSanctionWithReport($world, $creator);

        $results = $this->repo->createFilteredQuery($world['centre'], $other, $world['year'])->getResult();

        self::assertCount(0, $results);
    }

    public function testNonAdminWithMultipleReportsOnSameSanctionGetsSingleResult(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('non.admin.dedup');
        $this->persist($teacher);
        $sanction = $this->makeSanction($world, $teacher);
        $this->makeReport($world, $teacher, $sanction);
        $this->makeReport($world, $teacher, $sanction);

        $results = $this->repo->createFilteredQuery($world['centre'], $teacher, $world['year'])->getResult();

        self::assertCount(1, $results);
    }

    // ── createFilteredQuery: filtros ─────────────────────────────────────────

    public function testCreateFilteredQuerySearchMatchesStudentAndGroupName(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeTeacher('filter.search', admin: true);
        $this->persist($admin);
        $this->makeSanctionWithReport($world, $admin);

        self::assertCount(1, $this->repo->createFilteredQuery($world['centre'], $admin, $world['year'], ['search' => 'garcía'])->getResult());
        self::assertCount(1, $this->repo->createFilteredQuery($world['centre'], $admin, $world['year'], ['search' => '1ºA'])->getResult());
        self::assertCount(0, $this->repo->createFilteredQuery($world['centre'], $admin, $world['year'], ['search' => 'nadie'])->getResult());
    }

    public function testCreateFilteredQueryPendingOnlyExcludesNotifiedSanctions(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeTeacher('filter.pending', admin: true);
        $this->persist($admin);
        $this->makeSanctionWithReport($world, $admin);
        $pending = $this->makeUnnotifiedSanctionWithReport($world, $admin);
        $this->flush();

        $results = $this->repo->createFilteredQuery($world['centre'], $admin, $world['year'], ['pendingOnly' => true])->getResult();

        self::assertCount(1, $results);
        self::assertSame($pending->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testCreateFilteredQueryEffectiveTodayReturnsOnlyNotifiedSanctionsInRange(): void
    {
        $world = $this->makeWorld();
        $admin = $this->makeTeacher('filter.effective', admin: true);
        $this->persist($admin);

        $current = $this->makeSanctionWithReport($world, $admin);
        $current->setEffectiveFrom(new \DateTimeImmutable('-2 days'))
                ->setEffectiveTo(new \DateTimeImmutable('+2 days'));

        $expired = $this->makeSanctionWithReport($world, $admin);
        $expired->setEffectiveFrom(new \DateTimeImmutable('-10 days'))
                ->setEffectiveTo(new \DateTimeImmutable('-5 days'));

        // In range but never notified: must not count as in effect
        $unnotified = $this->makeUnnotifiedSanctionWithReport($world, $admin);
        $unnotified->setEffectiveFrom(new \DateTimeImmutable('-2 days'))
                   ->setEffectiveTo(new \DateTimeImmutable('+2 days'));
        $this->flush();

        $results = $this->repo->createFilteredQuery($world['centre'], $admin, $world['year'], ['effectiveToday' => true])->getResult();

        self::assertCount(1, $results);
        self::assertSame($current->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    // ── createFilteredQuery: aislamiento por curso académico ──────────────────

    public function testCreateFilteredQueryRestrictsToGivenAcademicYear(): void
    {
        $worldA = $this->makeWorld();
        $worldB = $this->makeOtherYearInSameCentre($worldA);
        $admin  = $this->makeTeacher('admin.year.isolation', admin: true);
        $this->persist($admin);
        $sanctionA = $this->makeSanctionWithReport($worldA, $admin);
        $sanctionB = $this->makeSanctionWithReport($worldB, $admin);

        $resultsA = $this->repo->createFilteredQuery($worldA['centre'], $admin, $worldA['year'])->getResult();
        $resultsB = $this->repo->createFilteredQuery($worldA['centre'], $admin, $worldB['year'])->getResult();

        self::assertCount(1, $resultsA);
        self::assertSame($sanctionA->getId()->toRfc4122(), $resultsA[0]->getId()->toRfc4122());
        self::assertCount(1, $resultsB);
        self::assertSame($sanctionB->getId()->toRfc4122(), $resultsB[0]->getId()->toRfc4122());
    }

    // ── countActiveByCentre ────────────────────────────────────────────────────

    public function testCountActiveByCentreCountsNotifiedSanctionsInEffectToday(): void
    {
        $world  = $this->makeWorld();
        $admin  = $this->makeTeacher('admin.active.count', admin: true);
        $this->persist($admin);
        $active = $this->makeSanctionWithReport($world, $admin);
        $active->setEffectiveFrom(new \DateTimeImmutable('-2 days'))
               ->setEffectiveTo(new \DateTimeImmutable('+2 days'));
        $this->flush();

        $count = $this->repo->countActiveByCentre($world['centre'], $admin, new \DateTimeImmutable(), $world['year']);

        self::assertSame(1, $count);
    }

    public function testCountActiveByCentreRestrictsToGivenAcademicYear(): void
    {
        $worldA = $this->makeWorld();
        $worldB = $this->makeOtherYearInSameCentre($worldA);
        $admin  = $this->makeTeacher('admin.active.year', admin: true);
        $this->persist($admin);
        $sanctionA = $this->makeSanctionWithReport($worldA, $admin);
        $sanctionA->setEffectiveFrom(new \DateTimeImmutable('-2 days'))->setEffectiveTo(new \DateTimeImmutable('+2 days'));
        $sanctionB = $this->makeSanctionWithReport($worldB, $admin);
        $sanctionB->setEffectiveFrom(new \DateTimeImmutable('-2 days'))->setEffectiveTo(new \DateTimeImmutable('+2 days'));
        $this->flush();

        $today = new \DateTimeImmutable();

        self::assertSame(1, $this->repo->countActiveByCentre($worldA['centre'], $admin, $today, $worldA['year']));
        self::assertSame(1, $this->repo->countActiveByCentre($worldA['centre'], $admin, $today, $worldB['year']));
    }

    // ── findWithDatesForAcademicYear ──────────────────────────────────────────

    public function testFindWithDatesForAcademicYearExcludesSanctionsWithoutDates(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('dates.no.dates');
        $this->persist($teacher);
        $this->makeSanction($world, $teacher);

        $results = $this->repo->findWithDatesForAcademicYear($world['year']);

        self::assertCount(0, $results);
    }

    public function testFindWithDatesForAcademicYearReturnsSanctionsWithEffectiveFrom(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('dates.with.dates');
        $this->persist($teacher);
        $sanction = $this->makeSanction($world, $teacher);
        $sanction->setEffectiveFrom(new \DateTimeImmutable('2026-02-10'));
        $this->flush();

        $results = $this->repo->findWithDatesForAcademicYear($world['year']);

        self::assertCount(1, $results);
        self::assertSame($sanction->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testFindWithDatesForAcademicYearOrdersByEffectiveFromAscending(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('dates.order');
        $this->persist($teacher);
        $later   = $this->makeSanction($world, $teacher);
        $later->setEffectiveFrom(new \DateTimeImmutable('2026-03-01'));
        $earlier = $this->makeSanction($world, $teacher);
        $earlier->setEffectiveFrom(new \DateTimeImmutable('2026-01-15'));
        $this->flush();

        $results = $this->repo->findWithDatesForAcademicYear($world['year']);

        self::assertCount(2, $results);
        self::assertSame($earlier->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
        self::assertSame($later->getId()->toRfc4122(), $results[1]->getId()->toRfc4122());
    }

    public function testFindWithDatesForAcademicYearIsScopedToTheGivenYear(): void
    {
        $worldA   = $this->makeWorld('A');
        $worldB   = $this->makeWorld('B');
        $teacher  = $this->makeTeacher('dates.scope');
        $this->persist($teacher);
        $sanction = $this->makeSanction($worldA, $teacher);
        $sanction->setEffectiveFrom(new \DateTimeImmutable('2026-02-10'));
        $this->flush();

        $results = $this->repo->findWithDatesForAcademicYear($worldB['year']);

        self::assertCount(0, $results);
    }

    public function testFindWithDatesForAcademicYearExcludesUnnotifiedSanctions(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('dates.unnotified');
        $this->persist($teacher);
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($teacher)
            ->setDetails('Sin notificar')
            ->setNoMeasureApplied(false)
            ->setEffectiveFrom(new \DateTimeImmutable('2026-02-10'));
        $this->persist($sanction);

        $results = $this->repo->findWithDatesForAcademicYear($world['year']);

        self::assertCount(0, $results);
    }

    // ── findActiveOn ───────────────────────────────────────────────────────

    public function testFindActiveOnReturnsNotifiedSanctionInRange(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('active.on.range');
        $this->persist($teacher);
        $sanction = $this->makeSanction($world, $teacher);
        $sanction->setEffectiveFrom(new \DateTimeImmutable('2026-02-08'))
                 ->setEffectiveTo(new \DateTimeImmutable('2026-02-12'));
        $this->flush();

        $results = $this->repo->findActiveOn($world['year'], new \DateTimeImmutable('2026-02-10'));

        self::assertCount(1, $results);
        self::assertSame($sanction->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testFindActiveOnExcludesSanctionsOutsideRange(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('active.on.outside');
        $this->persist($teacher);
        $sanction = $this->makeSanction($world, $teacher);
        $sanction->setEffectiveFrom(new \DateTimeImmutable('2026-02-01'))
                 ->setEffectiveTo(new \DateTimeImmutable('2026-02-05'));
        $this->flush();

        $results = $this->repo->findActiveOn($world['year'], new \DateTimeImmutable('2026-02-10'));

        self::assertCount(0, $results);
    }

    public function testFindActiveOnIncludesOpenEndedSanctions(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('active.on.openended');
        $this->persist($teacher);
        $sanction = $this->makeSanction($world, $teacher);
        $sanction->setEffectiveFrom(new \DateTimeImmutable('2026-02-01'));
        $this->flush();

        $results = $this->repo->findActiveOn($world['year'], new \DateTimeImmutable('2026-06-15'));

        self::assertCount(1, $results);
        self::assertSame($sanction->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testFindActiveOnExcludesUnnotifiedSanctions(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('active.on.unnotified');
        $this->persist($teacher);
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($teacher)
            ->setDetails('Sin notificar')
            ->setNoMeasureApplied(false)
            ->setEffectiveFrom(new \DateTimeImmutable('2026-02-01'));
        $this->persist($sanction);

        $results = $this->repo->findActiveOn($world['year'], new \DateTimeImmutable('2026-02-10'));

        self::assertCount(0, $results);
    }

    public function testFindActiveOnIsScopedToTheGivenYear(): void
    {
        $worldA   = $this->makeWorld('A');
        $worldB   = $this->makeWorld('B');
        $teacher  = $this->makeTeacher('active.on.scope');
        $this->persist($teacher);
        $sanction = $this->makeSanction($worldA, $teacher);
        $sanction->setEffectiveFrom(new \DateTimeImmutable('2026-02-01'));
        $this->flush();

        $results = $this->repo->findActiveOn($worldB['year'], new \DateTimeImmutable('2026-02-10'));

        self::assertCount(0, $results);
    }

    // ── findEligibleReports ──────────────────────────────────────────────────

    public function testFindEligibleReportsReturnsUnprescribedUnsanctionedReports(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('eligible.creator');
        $this->persist($teacher);
        $report  = $this->makeReport($world, $teacher);

        $results = $this->repo->findEligibleReports($world['student'], $world['group']);

        self::assertCount(1, $results);
        self::assertSame($report->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testFindEligibleReportsExcludesPrescribedReports(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('eligible.prescribed');
        $this->persist($teacher);
        $report  = $this->makeReport($world, $teacher);
        $report->setPrescribedAt(new \DateTimeImmutable());
        $this->flush();

        $results = $this->repo->findEligibleReports($world['student'], $world['group']);

        self::assertCount(0, $results);
    }

    public function testFindEligibleReportsExcludesAlreadySanctionedReports(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('eligible.sanctioned');
        $this->persist($teacher);
        $sanction = $this->makeSanction($world, $teacher);
        $this->makeReport($world, $teacher, $sanction);

        $results = $this->repo->findEligibleReports($world['student'], $world['group']);

        self::assertCount(0, $results);
    }

    public function testFindEligibleReportsOnlyReturnsReportsForGivenStudentAndGroup(): void
    {
        $worldA  = $this->makeWorld('A');
        $worldB  = $this->makeWorld('B');
        $teacher = $this->makeTeacher('eligible.scope');
        $this->persist($teacher);
        $this->makeReport($worldA, $teacher);
        $this->makeReport($worldB, $teacher);

        $results = $this->repo->findEligibleReports($worldA['student'], $worldA['group']);

        self::assertCount(1, $results);
    }

    public function testFindEligibleReportsExcludesUnnotifiedReports(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('eligible.unnotified');
        $this->persist($teacher);
        $this->makeUnnotifiedReport($world, $teacher);

        $results = $this->repo->findEligibleReports($world['student'], $world['group']);

        self::assertCount(0, $results);
    }

    // ── findPendingNotification ───────────────────────────────────────────────

    public function testFindPendingNotificationExcludesNotifiedSanctions(): void
    {
        $world   = $this->makeWorld();
        $admin   = $this->makeTeacher('pending.admin', admin: true);
        $this->persist($admin);
        $pending = $this->makeUnnotifiedSanctionWithReport($world, $admin);
        $this->makeSanctionWithReport($world, $admin);

        $results = $this->repo->findPendingNotification($world['centre'], $admin, $world['year']);

        self::assertCount(1, $results);
        self::assertSame($pending->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testFindPendingNotificationRestrictsVisibilityForRegularTeacher(): void
    {
        $world = $this->makeWorld();
        $t1    = $this->makeTeacher('pending.t1');
        $t2    = $this->makeTeacher('pending.t2');
        $this->persist($t1, $t2);
        $own   = $this->makeUnnotifiedSanctionWithReport($world, $t1);
        $this->makeUnnotifiedSanctionWithReport($world, $t2);

        $results = $this->repo->findPendingNotification($world['centre'], $t1, $world['year']);

        self::assertCount(1, $results);
        self::assertSame($own->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testFindPendingNotificationRestrictsToGivenAcademicYear(): void
    {
        $worldA = $this->makeWorld();
        $worldB = $this->makeOtherYearInSameCentre($worldA);
        $admin  = $this->makeTeacher('pending.admin.year', admin: true);
        $this->persist($admin);
        $pendingA = $this->makeUnnotifiedSanctionWithReport($worldA, $admin);
        $this->makeUnnotifiedSanctionWithReport($worldB, $admin);

        $results = $this->repo->findPendingNotification($worldA['centre'], $admin, $worldA['year']);

        self::assertCount(1, $results);
        self::assertSame($pendingA->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    // ── createPendingQuery ────────────────────────────────────────────────────

    public function testCreatePendingQueryMatchesFindPendingNotification(): void
    {
        $world   = $this->makeWorld();
        $tutor   = $this->makeTeacher('pending.query.tutor');
        $creator = $this->makeTeacher('pending.query.creator');
        $this->persist($tutor, $creator);
        $world['group']->addTutor($tutor);
        $this->flush();
        $pending = $this->makeUnnotifiedSanctionWithReport($world, $creator);
        $this->makeSanctionWithReport($world, $creator);

        $expected = $this->repo->findPendingNotification($world['centre'], $tutor, $world['year']);
        $actual   = $this->repo->createPendingQuery($world['centre'], $tutor, $world['year'])->getResult();

        self::assertCount(1, $actual);
        self::assertCount(count($expected), $actual);
        self::assertSame($pending->getId()->toRfc4122(), $actual[0]->getId()->toRfc4122());
    }

    // ── findStudentStatsForCentre ────────────────────────────────────────────

    public function testFindStudentStatsReturnsEmptyWhenNoCentreActiveYear(): void
    {
        $centre = (new EducationalCentre())->setCode('41000999')->setName('IES No Year')->setCity('Sevilla');
        $this->persist($centre);

        $result = $this->repo->findStudentStatsForCentre($centre);

        self::assertSame(0, $result['total']);
        self::assertCount(0, $result['rows']);
    }

    public function testCountSanctionableByCentreReturnsZeroWhenNoCentreActiveYear(): void
    {
        $centre = (new EducationalCentre())->setCode('41000998')->setName('IES No Year')->setCity('Sevilla');
        $this->persist($centre);

        self::assertSame(0, $this->repo->countSanctionableByCentre($centre));
    }

    public function testCountSanctionableByCentreCountsNotifiedUnprescribedUnsanctionedReports(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('count.sanctionable');
        $this->persist($teacher);
        $world['group']->addStudent($world['student']);
        $this->flush();
        $this->makeReport($world, $teacher);
        $this->makeReport($world, $teacher);
        $this->makeUnnotifiedReport($world, $teacher);

        self::assertSame(2, $this->repo->countSanctionableByCentre($world['centre']));
    }

    public function testFindStudentStatsCountsSanctionableReports(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('stats.sanctionable');
        $this->persist($teacher);
        $world['group']->addStudent($world['student']);
        $this->flush();
        $this->makeReport($world, $teacher);
        $this->makeReport($world, $teacher);

        $result = $this->repo->findStudentStatsForCentre($world['centre']);

        self::assertSame(1, $result['total']);
        self::assertSame(2, $result['rows'][0]['sanctionableCount']);
        self::assertSame(0, $result['rows'][0]['seriousCount']);
    }

    public function testFindStudentStatsDoesNotCountUnnotifiedReportsAsSanctionable(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('stats.unnotified');
        $this->persist($teacher);
        $world['group']->addStudent($world['student']);
        $this->flush();

        // One notified report so the student appears in the list, one unnotified that must not count
        $this->makeReport($world, $teacher);
        $this->makeUnnotifiedReport($world, $teacher);

        $result = $this->repo->findStudentStatsForCentre($world['centre']);

        self::assertSame(1, $result['total']);
        self::assertSame(1, $result['rows'][0]['sanctionableCount']);
    }

    public function testFindStudentStatsExcludesStudentsWithOnlyUnnotifiedReports(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('stats.only.unnotified');
        $this->persist($teacher);
        $world['group']->addStudent($world['student']);
        $this->flush();

        $this->makeUnnotifiedReport($world, $teacher);

        $result = $this->repo->findStudentStatsForCentre($world['centre']);

        self::assertSame(0, $result['total']);
        self::assertCount(0, $result['rows']);
    }

    public function testFindStudentStatsCountsSeriousReports(): void
    {
        $world       = $this->makeWorld();
        $seriousCat  = (new IncidentBehaviorCategory())
            ->setEducationalCentre($world['centre'])
            ->setName('Graves')
            ->setSerious(true)
            ->setPosition(1);
        $seriousBeh  = (new IncidentBehavior())
            ->setEducationalCentre($world['centre'])
            ->setCategory($seriousCat)
            ->setName('Agresión')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($seriousCat, $seriousBeh);
        $teacher = $this->makeTeacher('stats.serious');
        $this->persist($teacher);
        $world['group']->addStudent($world['student']);
        $this->flush();

        $normal = $this->makeReport($world, $teacher);
        $serious = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($teacher)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Agresión</p>')
            ->setExpelledFromClass(false);
        $serious->addBehavior($seriousBeh);
        $this->persist($serious);
        $this->notify($serious, $world, $teacher);

        $result = $this->repo->findStudentStatsForCentre($world['centre']);

        self::assertSame(1, $result['total']);
        self::assertSame(2, $result['rows'][0]['sanctionableCount']);
        self::assertSame(1, $result['rows'][0]['seriousCount']);
    }

    public function testFindStudentStatsCountsPrescribedReports(): void
    {
        $world   = $this->makeWorld();
        $teacher = $this->makeTeacher('stats.prescribed');
        $this->persist($teacher);
        $world['group']->addStudent($world['student']);
        $this->flush();

        // One sanctionable report so the student appears in the list
        $this->makeReport($world, $teacher);
        // One prescribed report counted separately
        $prescribed = $this->makeReport($world, $teacher);
        $prescribed->setPrescribedAt(new \DateTimeImmutable());
        $this->flush();

        $result = $this->repo->findStudentStatsForCentre($world['centre']);

        self::assertSame(1, $result['total']);
        self::assertSame(1, $result['rows'][0]['sanctionableCount']);
        self::assertSame(1, $result['rows'][0]['prescribedCount']);
    }

    public function testFindStudentStatsSortsBySanctionableCountDesc(): void
    {
        $world    = $this->makeWorld();
        $studentB = (new Student(new PersonName('Bea', 'López')))->setStudentId('NIE-B-' . uniqid('', false));
        $this->persist($studentB);
        $teacher  = $this->makeTeacher('stats.sort');
        $this->persist($teacher);
        $world['group']->addStudent($world['student']);
        $world['group']->addStudent($studentB);
        $this->flush();

        // Student A (Ana) gets 1 report, Student B (Bea) gets 3 reports
        $this->makeReport($world, $teacher);

        for ($i = 0; $i < 3; $i++) {
            $r = (new IncidentReport())
                ->setAcademicYear($world['year'])
                ->setNumber(++$this->nextReportNumber)
                ->setStudent($studentB)
                ->setGroup($world['group'])
                ->setRegisteredBy($teacher)
                ->setOccurredAt(new \DateTimeImmutable())
                ->setDescription('<p>Test</p>')
                ->setExpelledFromClass(false);
            $r->addBehavior($world['behavior']);
            $this->persist($r);
            $this->notify($r, $world, $teacher);
        }

        $result = $this->repo->findStudentStatsForCentre($world['centre']);

        self::assertSame(2, $result['total']);
        // Bea (3 reports) should come first
        self::assertSame('López', $result['rows'][0]['lastName']);
        self::assertSame(3, $result['rows'][0]['sanctionableCount']);
        self::assertSame(1, $result['rows'][1]['sanctionableCount']);
    }

    public function testFindStudentStatsFiltersbyName(): void
    {
        $world    = $this->makeWorld();
        $studentB = (new Student(new PersonName('Pedro', 'Martínez')))->setStudentId('NIE-P-' . uniqid('', false));
        $this->persist($studentB);
        $teacher  = $this->makeTeacher('stats.filter');
        $this->persist($teacher);
        $world['group']->addStudent($world['student']);
        $world['group']->addStudent($studentB);
        $this->flush();

        // Both need at least one sanctionable report to appear; only García should match the name filter
        $this->makeReport($world, $teacher);
        $reportB = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($studentB)
            ->setGroup($world['group'])
            ->setRegisteredBy($teacher)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false);
        $reportB->addBehavior($world['behavior']);
        $this->persist($reportB);

        $result = $this->repo->findStudentStatsForCentre($world['centre'], 'García');

        self::assertSame(1, $result['total']);
        self::assertSame('García', $result['rows'][0]['lastName']);
    }

    public function testFindStudentStatsPaginatesResults(): void
    {
        $world    = $this->makeWorld();
        $teacher  = $this->makeTeacher('stats.page');
        $this->persist($teacher);
        $world['group']->addStudent($world['student']);

        /** @var list<Student> $extras */
        $extras = [];
        for ($i = 0; $i < 4; $i++) {
            $s = (new Student(new PersonName('Extra', 'Student' . $i)))->setStudentId('NIE-EX-' . $i . uniqid('', false));
            $this->persist($s);
            $world['group']->addStudent($s);
            $extras[] = $s;
        }
        $this->flush();

        // Give every student one sanctionable report so they all appear in the list
        $this->makeReport($world, $teacher);
        foreach ($extras as $extra) {
            $r = (new IncidentReport())
                ->setAcademicYear($world['year'])
                ->setNumber(++$this->nextReportNumber)
                ->setStudent($extra)
                ->setGroup($world['group'])
                ->setRegisteredBy($teacher)
                ->setOccurredAt(new \DateTimeImmutable())
                ->setDescription('<p>Test</p>')
                ->setExpelledFromClass(false);
            $r->addBehavior($world['behavior']);
            $this->persist($r);
            $this->notify($r, $world, $teacher);
        }

        $page1 = $this->repo->findStudentStatsForCentre($world['centre'], '', 1, 3);
        $page2 = $this->repo->findStudentStatsForCentre($world['centre'], '', 2, 3);

        self::assertSame(5, $page1['total']);
        self::assertCount(3, $page1['rows']);
        self::assertCount(2, $page2['rows']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod}
     */
    private function makeWorld(string $suffix = ''): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'r'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
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
     * Builds a second academic year for the SAME centre as $world, with its own
     * programme/group/student, to test that per-year listings are sealed off
     * from other years of the same centre (and not just from other centres).
     *
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod} $world
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod}
     */
    private function makeOtherYearInSameCentre(array $world, string $suffix = ''): array
    {
        $centre    = $world['centre'];
        $year      = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW-Y2' . $suffix)->setAcademicYear($year);
        $group     = (new Group())->setName('1ºB' . $suffix)->setCourse($course);
        $student   = (new Student(new PersonName('Bea', 'Ruiz')))->setStudentId('NIE-Y2-' . $suffix . uniqid('', false));

        $this->persist($year, $course, $group, $student);

        return ['centre' => $centre, 'year' => $year, 'group' => $group, 'student' => $student, 'behavior' => $world['behavior'], 'method' => $world['method']];
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod} $world
     */
    private function makeSanction(array $world, Teacher $creator): Sanction
    {
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setDetails('Detalles de prueba')
            ->setNoMeasureApplied(false);
        $this->persist($sanction);
        $this->notify($sanction, $world, $creator);

        return $sanction;
    }

    /**
     * Creates a sanction and immediately links a report to it (needed for non-admin visibility queries).
     *
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod} $world
     */
    private function makeSanctionWithReport(array $world, Teacher $creator): Sanction
    {
        $sanction = $this->makeSanction($world, $creator);
        $this->makeReport($world, $creator, $sanction);

        return $sanction;
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod} $world
     */
    private function makeReport(
        array $world,
        Teacher $creator,
        ?Sanction $sanction = null,
    ): IncidentReport {
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

        if ($sanction !== null) {
            $report->setSanction($sanction);
        }

        $this->persist($report);
        $this->notify($report, $world, $creator);

        return $report;
    }

    /**
     * Creates a report without registering any communication (stays pending notification).
     *
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod} $world
     */
    private function makeUnnotifiedReport(array $world, Teacher $creator): IncidentReport
    {
        $report = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Sin notificar</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($world['behavior']);
        $this->persist($report);

        return $report;
    }

    /**
     * Creates a sanction with a linked report, neither of which is notified.
     *
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod} $world
     */
    private function makeUnnotifiedSanctionWithReport(array $world, Teacher $creator): Sanction
    {
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setDetails('Sin notificar')
            ->setNoMeasureApplied(false);
        $this->persist($sanction);

        $report = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Sin notificar</p>')
            ->setExpelledFromClass(false)
            ->setSanction($sanction);
        $report->addBehavior($world['behavior']);
        $sanction->getReports()->add($report);
        $this->persist($report);

        return $sanction;
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, method: CommunicationMethod} $world
     */
    private function notify(IncidentReport|Sanction $target, array $world, Teacher $teacher): void
    {
        $communication = $target instanceof IncidentReport
            ? Communication::forIncidentReport($target, $world['method'], $teacher, new \DateTimeImmutable(), CommunicationResult::Notified)
            : Communication::forSanction($target, $world['method'], $teacher, new \DateTimeImmutable(), CommunicationResult::Notified);
        $this->persist($communication);
        $target->setNotifiedCommunication($communication);
        $this->flush();
    }

    private function makeTeacher(string $username, bool $admin = false): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername($username)
            ->setAdmin($admin);
    }
}
