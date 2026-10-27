<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Entity\ResourceBookingStatus;
use App\Entity\ResourceCategory;
use App\Entity\SharedResource;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\NonWorkingDayRepository;
use App\Repository\ResourceBookingRepository;
use App\Repository\ResourceCategoryRepository;
use App\Service\AppSettingsInterface;
use App\Service\NonWorkingDayChecker;
use App\Service\ResourceBookingAccess;
use App\Service\ResourceBookingPlanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class ResourceBookingPlannerTest extends TestCase
{
    public function testWeeklyDatesStepSevenDaysAndIncludeTheLastOne(): void
    {
        $dates = $this->planner()->weeklyDates(new \DateTimeImmutable('2026-09-15'), new \DateTimeImmutable('2026-10-06'));

        self::assertSame(['2026-09-15', '2026-09-22', '2026-09-29', '2026-10-06'], array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates));
    }

    public function testWeeklyDatesAreEmptyWhenTheEndPrecedesTheStart(): void
    {
        self::assertSame([], $this->planner()->weeklyDates(new \DateTimeImmutable('2026-09-15'), new \DateTimeImmutable('2026-09-01')));
    }

    public function testWeeklyDatesAreCapped(): void
    {
        $dates = $this->planner()->weeklyDates(new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2030-09-14'));

        self::assertCount(ResourceBookingPlanner::MAX_OCCURRENCES, $dates);
    }

    public function testTodayComesFromTheClockAtMidnight(): void
    {
        self::assertSame('2026-09-14 00:00:00', $this->planner()->today()->format('Y-m-d H:i:s'));
    }

    public function testTheCategoryLimitWinsOverTheCentreSetting(): void
    {
        [$resource] = $this->resource(maxAdvance: 5);

        self::assertSame(5, $this->planner(centreLimit: 90)->maxAdvanceDays($resource));
    }

    public function testWithoutACategoryLimitTheCentreSettingApplies(): void
    {
        [$resource] = $this->resource();

        self::assertSame(90, $this->planner(centreLimit: 90)->maxAdvanceDays($resource));
        self::assertSame(0, $this->planner(centreLimit: null)->maxAdvanceDays($resource));
    }

    public function testACategoryLimitOfZeroMeansNoLimitEvenWithACentreSetting(): void
    {
        [$resource] = $this->resource(maxAdvance: 0);

        self::assertSame(0, $this->planner(centreLimit: 90)->maxAdvanceDays($resource));
    }

    public function testNewBookingsAreApprovedUnlessTheCategoryNeedsValidationAndTheActorIsNotAManager(): void
    {
        [$resource, $category] = $this->resource(approval: true);
        $plain   = new Teacher(new PersonName('A', 'B'));
        $manager = new Teacher(new PersonName('C', 'D'));
        $category->addManager($manager);
        $planner = $this->planner();

        self::assertSame(ResourceBookingStatus::Pending, $planner->initialStatus($resource, $plain));
        self::assertSame(ResourceBookingStatus::Approved, $planner->initialStatus($resource, $manager));

        [$free] = $this->resource(approval: false);
        self::assertSame(ResourceBookingStatus::Approved, $planner->initialStatus($free, $plain));
    }

    private function planner(?int $centreLimit = 0): ResourceBookingPlanner
    {
        $settings = $this->createStub(AppSettingsInterface::class);
        $settings->method('getForCentre')->willReturn($centreLimit);

        return new ResourceBookingPlanner(
            $this->createStub(ResourceBookingRepository::class),
            new NonWorkingDayChecker($this->createStub(NonWorkingDayRepository::class)),
            new ResourceBookingAccess($this->createStub(ResourceCategoryRepository::class)),
            $settings,
            new MockClock('2026-09-14 11:30:00'),
        );
    }

    /**
     * @return array{0: SharedResource, 1: ResourceCategory}
     */
    private function resource(?int $maxAdvance = null, bool $approval = false): array
    {
        $category = (new ResourceCategory())
            ->setEducationalCentre(new EducationalCentre())
            ->setName('Dependencias')
            ->setMaxAdvanceDays($maxAdvance)
            ->setRequiresApproval($approval);
        $resource = (new SharedResource())->setCategory($category)->setName('Aula');

        return [$resource, $category];
    }
}
