<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\TimeSlot;
use App\Repository\TimeSlotRepository;
use App\Tests\Integration\RepositoryTestCase;

class TimeSlotRepositoryTest extends RepositoryTestCase
{
    private TimeSlotRepository $repo;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var TimeSlotRepository $repo */
        $repo       = self::getContainer()->get(TimeSlotRepository::class);
        $this->repo = $repo;

        $centre     = (new EducationalCentre())->setCode('43000001')->setName('IES Test')->setCity('Sevilla');
        $this->year = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $this->year);
    }

    // ── findByAcademicYearOrdered ────────────────────────────────────────────

    public function testFindByAcademicYearOrderedSortsByDayThenStartTime(): void
    {
        $this->persist(
            $this->makeSlot('Recreo', 0, '11:00', '11:30'),
            $this->makeSlot('1ª hora', 0, '08:00', '08:55'),
            $this->makeSlot('1ª hora martes', 1, '08:00', '08:55'),
        );

        $results = $this->repo->findByAcademicYearOrdered($this->year);

        self::assertCount(3, $results);
        self::assertSame('1ª hora', $results[0]->getName());
        self::assertSame('Recreo', $results[1]->getName());
        self::assertSame('1ª hora martes', $results[2]->getName());
    }

    public function testFindByAcademicYearOrderedOnlyReturnsSlotsForGivenYear(): void
    {
        $otherCentre = (new EducationalCentre())->setCode('43000002')->setName('IES Otro')->setCity('Cádiz');
        $otherYear   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($otherCentre);
        $this->persist($otherCentre, $otherYear);

        $this->persist(
            $this->makeSlot('Propio', 0, '08:00', '08:55'),
            $this->makeSlotForYear($otherYear, 'Ajeno', 0, '08:00', '08:55'),
        );

        $results = $this->repo->findByAcademicYearOrdered($this->year);

        self::assertCount(1, $results);
        self::assertSame('Propio', $results[0]->getName());
    }

    // ── findByAcademicYearAndDay ─────────────────────────────────────────────

    public function testFindByAcademicYearAndDayFiltersByDayAndOrdersByStartTime(): void
    {
        $this->persist(
            $this->makeSlot('Recreo', 1, '11:00', '11:30'),
            $this->makeSlot('1ª hora', 1, '08:00', '08:55'),
            $this->makeSlot('Otro día', 2, '08:00', '08:55'),
        );

        $results = $this->repo->findByAcademicYearAndDay($this->year, 1);

        self::assertCount(2, $results);
        self::assertSame('1ª hora', $results[0]->getName());
        self::assertSame('Recreo', $results[1]->getName());
    }

    public function testFindByAcademicYearAndDayReturnsEmptyForDayWithoutSlots(): void
    {
        $this->persist($this->makeSlot('1ª hora', 0, '08:00', '08:55'));

        self::assertSame([], $this->repo->findByAcademicYearAndDay($this->year, 4));
    }

    // ── findByAcademicYearAndId ──────────────────────────────────────────────

    public function testFindByAcademicYearAndIdReturnsMatchingSlot(): void
    {
        $slot = $this->makeSlot('1ª hora', 0, '08:00', '08:55');
        $this->persist($slot);

        $result = $this->repo->findByAcademicYearAndId($this->year, $slot->getId()->toRfc4122());

        self::assertNotNull($result);
        self::assertSame('1ª hora', $result->getName());
    }

    public function testFindByAcademicYearAndIdReturnsNullForOtherYear(): void
    {
        $otherCentre = (new EducationalCentre())->setCode('43000003')->setName('IES Otro')->setCity('Cádiz');
        $otherYear   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($otherCentre);
        $this->persist($otherCentre, $otherYear);

        $slot = $this->makeSlotForYear($otherYear, 'Ajeno', 0, '08:00', '08:55');
        $this->persist($slot);

        self::assertNull($this->repo->findByAcademicYearAndId($this->year, $slot->getId()->toRfc4122()));
    }

    public function testFindByAcademicYearAndIdReturnsNullForNonExistentId(): void
    {
        self::assertNull($this->repo->findByAcademicYearAndId($this->year, '00000000-0000-0000-0000-000000000000'));
    }

    // ── countByAcademicYear ──────────────────────────────────────────────────

    public function testCountByAcademicYearCountsOnlySlotsForGivenYear(): void
    {
        $this->persist(
            $this->makeSlot('1ª hora', 0, '08:00', '08:55'),
            $this->makeSlot('Recreo', 0, '11:00', '11:30'),
        );

        self::assertSame(2, $this->repo->countByAcademicYear($this->year));
    }

    public function testCountByAcademicYearReturnsZeroWhenNoneExist(): void
    {
        self::assertSame(0, $this->repo->countByAcademicYear($this->year));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeSlot(string $name, int $day, string $start, string $end): TimeSlot
    {
        return $this->makeSlotForYear($this->year, $name, $day, $start, $end);
    }

    private function makeSlotForYear(AcademicYear $year, string $name, int $day, string $start, string $end): TimeSlot
    {
        return (new TimeSlot())
            ->setAcademicYear($year)
            ->setName($name)
            ->setDayOfWeek($day)
            ->setStartTime(\DateTimeImmutable::createFromFormat('H:i', $start))
            ->setEndTime(\DateTimeImmutable::createFromFormat('H:i', $end));
    }
}
