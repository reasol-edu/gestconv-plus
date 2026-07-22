<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\AcademicYear;
use App\Entity\NonWorkingDay;
use App\Repository\NonWorkingDayRepository;
use App\Service\NonWorkingDayChecker;
use PHPUnit\Framework\TestCase;

class NonWorkingDayCheckerTest extends TestCase
{
    private function nonWorkingDay(string $date, ?string $description = null): NonWorkingDay
    {
        $day = new NonWorkingDay();
        $day->setDate(new \DateTimeImmutable($date));
        $day->setDescription($description);

        return $day;
    }

    public function testIsWeekendDetectsSaturdayAndSunday(): void
    {
        $checker = new NonWorkingDayChecker($this->createStub(NonWorkingDayRepository::class));

        self::assertTrue($checker->isWeekend(new \DateTimeImmutable('2026-07-25'))); // sábado
        self::assertTrue($checker->isWeekend(new \DateTimeImmutable('2026-07-26'))); // domingo
        self::assertFalse($checker->isWeekend(new \DateTimeImmutable('2026-07-24'))); // viernes
    }

    public function testIsNonWorkingDayIsTrueOnWeekendWithoutQueryingRepository(): void
    {
        $repository = $this->createMock(NonWorkingDayRepository::class);
        $repository->expects(self::never())->method('findByAcademicYearAndDate');

        $checker = new NonWorkingDayChecker($repository);
        $year    = $this->createStub(AcademicYear::class);

        self::assertTrue($checker->isNonWorkingDay($year, new \DateTimeImmutable('2026-07-25')));
    }

    public function testIsNonWorkingDayChecksDeclaredHolidaysOnWeekdays(): void
    {
        $year = $this->createStub(AcademicYear::class);
        $date = new \DateTimeImmutable('2026-07-24'); // viernes

        $repository = $this->createStub(NonWorkingDayRepository::class);
        $repository->method('findByAcademicYearAndDate')
            ->willReturn($this->nonWorkingDay('2026-07-24'));

        $checker = new NonWorkingDayChecker($repository);

        self::assertTrue($checker->isNonWorkingDay($year, $date));
    }

    public function testIsNonWorkingDayIsFalseOnOrdinaryWeekday(): void
    {
        $year = $this->createStub(AcademicYear::class);
        $date = new \DateTimeImmutable('2026-07-24');

        $repository = $this->createStub(NonWorkingDayRepository::class);
        $repository->method('findByAcademicYearAndDate')->willReturn(null);

        $checker = new NonWorkingDayChecker($repository);

        self::assertFalse($checker->isNonWorkingDay($year, $date));
    }

    public function testDescriptionForReturnsHolidayDescription(): void
    {
        $year = $this->createStub(AcademicYear::class);
        $date = new \DateTimeImmutable('2026-07-24');

        $repository = $this->createStub(NonWorkingDayRepository::class);
        $repository->method('findByAcademicYearAndDate')
            ->willReturn($this->nonWorkingDay('2026-07-24', 'Día del centro'));

        $checker = new NonWorkingDayChecker($repository);

        self::assertSame('Día del centro', $checker->descriptionFor($year, $date));
    }

    public function testCountSchoolDaysExcludesWeekendsAndHolidays(): void
    {
        $year = $this->createStub(AcademicYear::class);

        // lunes 2026-07-20 .. domingo 2026-07-26, con festivo el miércoles 22
        $repository = $this->createStub(NonWorkingDayRepository::class);
        $repository->method('findByAcademicYearOrdered')
            ->willReturn([$this->nonWorkingDay('2026-07-22')]);

        $checker = new NonWorkingDayChecker($repository);

        $count = $checker->countSchoolDays(
            $year,
            new \DateTimeImmutable('2026-07-20'),
            new \DateTimeImmutable('2026-07-26'),
        );

        // lectivos: 20 (lun), 21 (mar), 23 (jue), 24 (vie) = 4
        self::assertSame(4, $count);
    }

    public function testAddSchoolDaysIsReciprocalWithCountSchoolDays(): void
    {
        $year = $this->createStub(AcademicYear::class);

        $repository = $this->createStub(NonWorkingDayRepository::class);
        $repository->method('findByAcademicYearOrdered')
            ->willReturn([$this->nonWorkingDay('2026-07-22')]);

        $checker = new NonWorkingDayChecker($repository);

        $from = new \DateTimeImmutable('2026-07-20');
        $end  = $checker->addSchoolDays($year, $from, 4);

        self::assertSame('2026-07-24', $end->format('Y-m-d'));
        self::assertSame(4, $checker->countSchoolDays($year, $from, $end));
    }

    public function testAddSchoolDaysOfOneReturnsStartDateWhenItIsAlreadyASchoolDay(): void
    {
        $year = $this->createStub(AcademicYear::class);

        $repository = $this->createStub(NonWorkingDayRepository::class);
        $repository->method('findByAcademicYearOrdered')->willReturn([]);

        $checker = new NonWorkingDayChecker($repository);

        $from = new \DateTimeImmutable('2026-07-20');
        $end  = $checker->addSchoolDays($year, $from, 1);

        self::assertSame($from->format('Y-m-d'), $end->format('Y-m-d'));
    }
}
