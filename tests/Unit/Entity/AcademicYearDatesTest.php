<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AcademicYear;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fechas de comienzo y de fin del curso académico y sus valores por defecto. */
class AcademicYearDatesTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function names(): iterable
    {
        yield 'dos años' => ['2025-2026', '2025-09-15', '2026-09-14'];
        yield 'dos años con barra' => ['2026/2027', '2026-09-15', '2027-09-14'];
        yield 'con texto alrededor' => ['Curso 2024-25', '2024-09-15', '2025-09-14'];
    }

    #[DataProvider('names')]
    public function testDefaultDatesComeFromTheFirstYearOfTheName(string $name, string $start, string $end): void
    {
        [$from, $to] = AcademicYear::defaultDates($name);

        self::assertSame($start, $from->format('Y-m-d'));
        self::assertSame($end, $to->format('Y-m-d'));
    }

    public function testWithoutAYearInTheNameItIsTheCourseThatContainsToday(): void
    {
        [$from, $to] = AcademicYear::defaultDates('Curso de prueba', new \DateTimeImmutable('2026-03-01'));
        self::assertSame(['2025-09-15', '2026-09-14'], [$from->format('Y-m-d'), $to->format('Y-m-d')]);

        // El 14 de septiembre todavía es del curso anterior; el 15 ya empieza el nuevo.
        [$from] = AcademicYear::defaultDates(null, new \DateTimeImmutable('2026-09-14'));
        self::assertSame('2025-09-15', $from->format('Y-m-d'));
        [$from] = AcademicYear::defaultDates(null, new \DateTimeImmutable('2026-09-15'));
        self::assertSame('2026-09-15', $from->format('Y-m-d'));
    }

    public function testANewYearGetsItsDatesFromItsNameAndSetDatesOverridesThem(): void
    {
        $year = (new AcademicYear())->setName('2027-2028');
        self::assertSame('2027-09-15', $year->getStartDate()->format('Y-m-d'));
        self::assertSame('2028-09-14', $year->getEndDate()->format('Y-m-d'));

        $year->setDates(new \DateTimeImmutable('2027-09-06 13:00'), new \DateTimeImmutable('2028-06-23'));
        self::assertSame('2027-09-06', $year->getStartDate()->format('Y-m-d'));
        self::assertSame('00:00', $year->getStartDate()->format('H:i'));
    }

    public function testContainsIncludesBothEnds(): void
    {
        $year = (new AcademicYear())->setName('2025-2026');

        self::assertTrue($year->contains(new \DateTimeImmutable('2025-09-15')));
        self::assertTrue($year->contains(new \DateTimeImmutable('2026-09-14 18:30')));
        self::assertFalse($year->contains(new \DateTimeImmutable('2025-09-14')));
        self::assertFalse($year->contains(new \DateTimeImmutable('2026-09-15')));
    }
}
