<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CalendarBoardBuilder;
use App\Service\GroupColorPalette;
use PHPUnit\Framework\TestCase;

class CalendarBoardBuilderTest extends TestCase
{
    private CalendarBoardBuilder $builder;

    /** @var list<\DateTimeImmutable> Mon 2026-02-02 .. Fri 2026-02-06 */
    private array $weekDays;

    protected function setUp(): void
    {
        $this->builder  = new CalendarBoardBuilder(new GroupColorPalette());
        $this->weekDays = [
            new \DateTimeImmutable('2026-02-02'),
            new \DateTimeImmutable('2026-02-03'),
            new \DateTimeImmutable('2026-02-04'),
            new \DateTimeImmutable('2026-02-05'),
            new \DateTimeImmutable('2026-02-06'),
        ];
    }

    private function item(string $groupId, string $groupName, string $student, string $details, \DateTimeImmutable $from, ?\DateTimeImmutable $to = null): array
    {
        return [
            'groupId'   => $groupId,
            'groupName' => $groupName,
            'student'   => $student,
            'details'   => $details,
            'from'      => $from,
            'to'        => $to,
        ];
    }

    public function testReturnsOneEntryPerDay(): void
    {
        $result = $this->builder->build([], $this->weekDays);

        self::assertCount(5, $result);
        self::assertSame($this->weekDays[0], $result[0]['day']);
        self::assertSame([], $result[0]['groups']);
    }

    public function testSingleDayItemOnlyAppearsOnItsDay(): void
    {
        $items = [
            $this->item('g1', '1ºA', 'Ana García', 'Falta grave', new \DateTimeImmutable('2026-02-04')),
        ];

        $result = $this->builder->build($items, $this->weekDays);

        self::assertSame([], $result[0]['groups']); // lunes
        self::assertSame([], $result[1]['groups']); // martes
        self::assertCount(1, $result[2]['groups']); // miércoles
        self::assertSame([], $result[3]['groups']); // jueves
        self::assertSame([], $result[4]['groups']); // viernes
    }

    public function testMultiDayItemAppearsOnEveryCoveredDay(): void
    {
        $items = [
            $this->item('g1', '1ºA', 'Ana García', 'Expulsión', new \DateTimeImmutable('2026-02-03'), new \DateTimeImmutable('2026-02-05')),
        ];

        $result = $this->builder->build($items, $this->weekDays);

        self::assertSame([], $result[0]['groups']); // lunes: fuera de rango
        self::assertCount(1, $result[1]['groups']); // martes
        self::assertCount(1, $result[2]['groups']); // miércoles
        self::assertCount(1, $result[3]['groups']); // jueves
        self::assertSame([], $result[4]['groups']); // viernes: fuera de rango
    }

    public function testItemsAreGroupedByGroupId(): void
    {
        $day = new \DateTimeImmutable('2026-02-04');
        $items = [
            $this->item('g1', '1ºA', 'Ana García', '', $day),
            $this->item('g2', '1ºB', 'Bea López', '', $day),
            $this->item('g1', '1ºA', 'Carla Ruiz', '', $day),
        ];

        $result = $this->builder->build($items, $this->weekDays);
        $groups = $result[2]['groups'];

        self::assertCount(2, $groups);
        $byName = array_column($groups, null, 'name');
        self::assertCount(2, $byName['1ºA']['items']);
        self::assertCount(1, $byName['1ºB']['items']);
    }

    public function testGroupsAreSortedAlphabeticallyByName(): void
    {
        $day = new \DateTimeImmutable('2026-02-04');
        $items = [
            $this->item('g2', 'Zeta', 'X', '', $day),
            $this->item('g1', 'Alfa', 'Y', '', $day),
        ];

        $result = $this->builder->build($items, $this->weekDays);
        $names  = array_column($result[2]['groups'], 'name');

        self::assertSame(['Alfa', 'Zeta'], $names);
    }

    public function testItemsWithinAGroupAreSortedByStudentName(): void
    {
        $day = new \DateTimeImmutable('2026-02-04');
        $items = [
            $this->item('g1', '1ºA', 'Zoe', '', $day),
            $this->item('g1', '1ºA', 'Ana', '', $day),
        ];

        $result   = $this->builder->build($items, $this->weekDays);
        $students = array_column($result[2]['groups'][0]['items'], 'student');

        self::assertSame(['Ana', 'Zoe'], $students);
    }

    public function testSameGroupIdAlwaysGetsTheSameColor(): void
    {
        $day = new \DateTimeImmutable('2026-02-04');
        $items = [
            $this->item('g1', '1ºA', 'Ana', '', $day),
        ];

        $palette = new GroupColorPalette();
        $result  = (new CalendarBoardBuilder($palette))->build($items, $this->weekDays);

        self::assertSame($palette->colorFor('g1'), $result[2]['groups'][0]['color']);
    }

    public function testItemWithoutEndDateIsTreatedAsSingleDay(): void
    {
        $items = [
            $this->item('g1', '1ºA', 'Ana', '', new \DateTimeImmutable('2026-02-04'), null),
        ];

        $result = $this->builder->build($items, $this->weekDays);

        self::assertNull($result[2]['groups'][0]['items'][0]['to']);
        self::assertCount(0, $result[3]['groups']); // no aparece el día siguiente
    }
}
