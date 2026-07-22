<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Repository\NonWorkingDayRepository;
use App\Tests\Integration\RepositoryTestCase;

class NonWorkingDayRepositoryTest extends RepositoryTestCase
{
    private NonWorkingDayRepository $repo;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var NonWorkingDayRepository $repo */
        $repo       = self::getContainer()->get(NonWorkingDayRepository::class);
        $this->repo = $repo;

        $centre     = (new EducationalCentre())->setCode('43000001')->setName('IES Test')->setCity('Sevilla');
        $this->year = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $this->year);
    }

    // ── findByAcademicYearOrdered ────────────────────────────────────────────

    public function testFindByAcademicYearOrderedSortsByDate(): void
    {
        $this->persist(
            $this->makeDay('2025-12-08', 'Puente de la Constitución'),
            $this->makeDay('2025-10-13', 'Día de la Hispanidad'),
            $this->makeDay('2026-03-19', 'San José'),
        );

        $results = $this->repo->findByAcademicYearOrdered($this->year);

        self::assertCount(3, $results);
        self::assertSame('2025-10-13', $results[0]->getDate()->format('Y-m-d'));
        self::assertSame('2025-12-08', $results[1]->getDate()->format('Y-m-d'));
        self::assertSame('2026-03-19', $results[2]->getDate()->format('Y-m-d'));
    }

    public function testFindByAcademicYearOrderedOnlyReturnsDaysForGivenYear(): void
    {
        $otherCentre = (new EducationalCentre())->setCode('43000002')->setName('IES Otro')->setCity('Cádiz');
        $otherYear   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($otherCentre);
        $this->persist($otherCentre, $otherYear);

        $this->persist(
            $this->makeDay('2025-10-13', 'Propio'),
            $this->makeDayForYear($otherYear, '2025-10-13', 'Ajeno'),
        );

        $results = $this->repo->findByAcademicYearOrdered($this->year);

        self::assertCount(1, $results);
        self::assertSame('Propio', $results[0]->getDescription());
    }

    // ── findByAcademicYearAndId ──────────────────────────────────────────────

    public function testFindByAcademicYearAndIdReturnsMatchingDay(): void
    {
        $day = $this->makeDay('2025-10-13', 'Día de la Hispanidad');
        $this->persist($day);

        $result = $this->repo->findByAcademicYearAndId($this->year, $day->getId()->toRfc4122());

        self::assertNotNull($result);
        self::assertSame('Día de la Hispanidad', $result->getDescription());
    }

    public function testFindByAcademicYearAndIdReturnsNullForOtherYear(): void
    {
        $otherCentre = (new EducationalCentre())->setCode('43000003')->setName('IES Otro')->setCity('Cádiz');
        $otherYear   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($otherCentre);
        $this->persist($otherCentre, $otherYear);

        $day = $this->makeDayForYear($otherYear, '2025-10-13', 'Ajeno');
        $this->persist($day);

        self::assertNull($this->repo->findByAcademicYearAndId($this->year, $day->getId()->toRfc4122()));
    }

    public function testFindByAcademicYearAndIdReturnsNullForNonExistentId(): void
    {
        self::assertNull($this->repo->findByAcademicYearAndId($this->year, '00000000-0000-0000-0000-000000000000'));
    }

    // ── findByAcademicYearAndDate ─────────────────────────────────────────────

    public function testFindByAcademicYearAndDateReturnsMatchingDay(): void
    {
        $this->persist($this->makeDay('2025-10-13', 'Día de la Hispanidad'));

        $result = $this->repo->findByAcademicYearAndDate($this->year, new \DateTimeImmutable('2025-10-13'));

        self::assertNotNull($result);
        self::assertSame('Día de la Hispanidad', $result->getDescription());
    }

    public function testFindByAcademicYearAndDateReturnsNullWhenNoMatch(): void
    {
        $this->persist($this->makeDay('2025-10-13', 'Día de la Hispanidad'));

        self::assertNull($this->repo->findByAcademicYearAndDate($this->year, new \DateTimeImmutable('2025-12-08')));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeDay(string $date, ?string $description = null): NonWorkingDay
    {
        return $this->makeDayForYear($this->year, $date, $description);
    }

    private function makeDayForYear(AcademicYear $year, string $date, ?string $description = null): NonWorkingDay
    {
        return (new NonWorkingDay())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable($date))
            ->setDescription($description);
    }
}
