<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CalendarSegmentBuilder;
use PHPUnit\Framework\TestCase;

class CalendarSegmentBuilderTest extends TestCase
{
    private CalendarSegmentBuilder $builder;

    /** @var list<\DateTimeImmutable> Mon 2026-02-02 .. Fri 2026-02-06 */
    private array $weekDays;

    protected function setUp(): void
    {
        $this->builder  = new CalendarSegmentBuilder();
        $this->weekDays = [
            new \DateTimeImmutable('2026-02-02'),
            new \DateTimeImmutable('2026-02-03'),
            new \DateTimeImmutable('2026-02-04'),
            new \DateTimeImmutable('2026-02-05'),
            new \DateTimeImmutable('2026-02-06'),
        ];
    }

    public function testSingleDayEventGetsColumnMatchingItsWeekday(): void
    {
        $result = $this->builder->build(
            [['id' => 'a', 'start' => new \DateTimeImmutable('2026-02-04'), 'end' => new \DateTimeImmutable('2026-02-04')]],
            $this->weekDays,
        );

        self::assertSame(0, $result['maxLane']);
        self::assertCount(1, $result['segments']);
        self::assertSame(2, $result['segments'][0]['startCol']);
        self::assertSame(1, $result['segments'][0]['span']);
        self::assertSame(0, $result['segments'][0]['lane']);
    }

    public function testMultiDayEventSpansSeveralColumns(): void
    {
        $result = $this->builder->build(
            [['id' => 'a', 'start' => new \DateTimeImmutable('2026-02-03'), 'end' => new \DateTimeImmutable('2026-02-05')]],
            $this->weekDays,
        );

        self::assertSame(1, $result['segments'][0]['startCol']);
        self::assertSame(3, $result['segments'][0]['span']);
    }

    public function testEventIsClampedToVisibleDayBoundaries(): void
    {
        $result = $this->builder->build(
            [['id' => 'a', 'start' => new \DateTimeImmutable('2026-01-30'), 'end' => new \DateTimeImmutable('2026-02-10')]],
            $this->weekDays,
        );

        self::assertSame(0, $result['segments'][0]['startCol']);
        self::assertSame(5, $result['segments'][0]['span']);
    }

    public function testEventEntirelyOutsideVisibleDaysIsExcluded(): void
    {
        $result = $this->builder->build(
            [['id' => 'a', 'start' => new \DateTimeImmutable('2026-01-01'), 'end' => new \DateTimeImmutable('2026-01-05')]],
            $this->weekDays,
        );

        self::assertSame([], $result['segments']);
        self::assertSame(-1, $result['maxLane']);
    }

    public function testEventEntirelyOnADayNotInTheVisibleListIsExcluded(): void
    {
        // Simula un fin de semana sin clase: el miércoles no está en la lista de días visibles.
        $days = [
            new \DateTimeImmutable('2026-02-02'), // lunes
            new \DateTimeImmutable('2026-02-03'), // martes
            new \DateTimeImmutable('2026-02-05'), // jueves
            new \DateTimeImmutable('2026-02-06'), // viernes
        ];

        $result = $this->builder->build(
            [['id' => 'a', 'start' => new \DateTimeImmutable('2026-02-04'), 'end' => new \DateTimeImmutable('2026-02-04')]],
            $days,
        );

        self::assertSame([], $result['segments']);
    }

    public function testEventStartingOnAGapDaySnapsToTheNextVisibleDay(): void
    {
        // El miércoles no está en la lista (p. ej. festivo); el evento arranca ahí pero
        // se extiende hasta el jueves, así que debe aparecer a partir del jueves.
        $days = [
            new \DateTimeImmutable('2026-02-02'), // lunes
            new \DateTimeImmutable('2026-02-03'), // martes
            new \DateTimeImmutable('2026-02-05'), // jueves
            new \DateTimeImmutable('2026-02-06'), // viernes
        ];

        $result = $this->builder->build(
            [['id' => 'a', 'start' => new \DateTimeImmutable('2026-02-04'), 'end' => new \DateTimeImmutable('2026-02-05')]],
            $days,
        );

        self::assertSame(2, $result['segments'][0]['startCol']);
        self::assertSame(1, $result['segments'][0]['span']);
    }

    public function testOverlappingEventsGetDifferentLanes(): void
    {
        $result = $this->builder->build(
            [
                ['id' => 'a', 'start' => new \DateTimeImmutable('2026-02-02'), 'end' => new \DateTimeImmutable('2026-02-04')],
                ['id' => 'b', 'start' => new \DateTimeImmutable('2026-02-03'), 'end' => new \DateTimeImmutable('2026-02-05')],
            ],
            $this->weekDays,
        );

        self::assertSame(1, $result['maxLane']);
        $lanes = array_column($result['segments'], 'lane', 'id');
        self::assertNotSame($lanes['a'], $lanes['b']);
    }

    public function testNonOverlappingEventsShareTheSameLane(): void
    {
        $result = $this->builder->build(
            [
                ['id' => 'a', 'start' => new \DateTimeImmutable('2026-02-02'), 'end' => new \DateTimeImmutable('2026-02-02')],
                ['id' => 'b', 'start' => new \DateTimeImmutable('2026-02-03'), 'end' => new \DateTimeImmutable('2026-02-03')],
            ],
            $this->weekDays,
        );

        self::assertSame(0, $result['maxLane']);
        $lanes = array_column($result['segments'], 'lane', 'id');
        self::assertSame(0, $lanes['a']);
        self::assertSame(0, $lanes['b']);
    }

    public function testEmptyVisibleDaysReturnsNoSegments(): void
    {
        $result = $this->builder->build(
            [['id' => 'a', 'start' => new \DateTimeImmutable('2026-02-02'), 'end' => new \DateTimeImmutable('2026-02-02')]],
            [],
        );

        self::assertSame([], $result['segments']);
        self::assertSame(-1, $result['maxLane']);
    }
}
