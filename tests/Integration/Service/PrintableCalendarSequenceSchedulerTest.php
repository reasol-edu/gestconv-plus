<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Service\PrintableCalendarSequenceScheduler;
use App\Tests\Integration\RepositoryTestCase;

/** Utilidades › Generador de calendarios: how a sequence's items are laid out over the days. */
final class PrintableCalendarSequenceSchedulerTest extends RepositoryTestCase
{
    private PrintableCalendarSequenceScheduler $scheduler;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var PrintableCalendarSequenceScheduler $scheduler */
        $scheduler       = self::getContainer()->get(PrintableCalendarSequenceScheduler::class);
        $this->scheduler = $scheduler;

        $centre     = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $this->year);
    }

    private function date(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }

    /** @return array<int, ?float> */
    private function eightHoursADay(): array
    {
        return [1 => 8.0, 2 => 8.0, 3 => 8.0, 4 => 8.0, 5 => 8.0];
    }

    public function testItemsAreLaidOutOneAfterAnotherSharingTheDayWhereOneEndsAndTheNextStarts(): void
    {
        // Monday 2 March 2026, 8 h a day: UD1 (20 h) = Mon 8 + Tue 8 + Wed 4; UD2 (10 h) = Wed 4 + Thu 6;
        // UD3 (12 h) = Thu 2 + Fri 8 + Mon 2.
        $schedule = $this->scheduler->schedule($this->year, $this->date('2026-03-02'), $this->eightHoursADay(), [20.0, 10.0, 12.0]);

        self::assertTrue($schedule['completed']);
        self::assertCount(3, $schedule['items']);
        self::assertEquals($this->date('2026-03-02'), $schedule['items'][0]['startDate']);
        self::assertEquals($this->date('2026-03-04'), $schedule['items'][0]['endDate']);
        self::assertEquals($this->date('2026-03-04'), $schedule['items'][1]['startDate']);
        self::assertEquals($this->date('2026-03-05'), $schedule['items'][1]['endDate']);
        self::assertEquals($this->date('2026-03-05'), $schedule['items'][2]['startDate']);
        self::assertEquals($this->date('2026-03-09'), $schedule['items'][2]['endDate']);
        self::assertSame([20.0, 10.0, 12.0], array_map(static fn (array $item): float => $item['hours'], $schedule['items']));
    }

    public function testADayIsOwnedByTheItemWithMostHoursThatDayAndOnATieByTheOneStarting(): void
    {
        $schedule = $this->scheduler->schedule($this->year, $this->date('2026-03-02'), $this->eightHoursADay(), [20.0, 10.0, 12.0]);

        $owners = [];
        foreach ($schedule['days'] as $day) {
            $owners[$day['date']->format('Y-m-d')] = $day['item'];
        }
        self::assertSame([
            '2026-03-02' => 0,
            '2026-03-03' => 0,
            '2026-03-04' => 1, // UD1 4 h and UD2 4 h: a tie, the one starting wins
            '2026-03-05' => 1, // UD2 6 h against UD3 2 h
            '2026-03-06' => 2,
            '2026-03-09' => 2,
        ], $owners);
        // A day always carries the day's total hours, not just the owner's share.
        self::assertSame(8.0, $schedule['days'][2]['hours']);
    }

    public function testNonWorkingDaysAreSkipped(): void
    {
        $holiday = (new NonWorkingDay())->setDate($this->date('2026-03-03'))->setDescription('Festivo')->setAcademicYear($this->year);
        $this->persist($holiday);

        $schedule = $this->scheduler->schedule($this->year, $this->date('2026-03-02'), $this->eightHoursADay(), [16.0]);

        self::assertEquals($this->date('2026-03-04'), $schedule['items'][0]['endDate']);
        self::assertCount(2, $schedule['items'][0]['days']);
    }

    public function testExcludedDatesAreSkippedLikeNonWorkingDays(): void
    {
        // Tuesday 3 and Wednesday 4 are not usable: 8 h a day, 16 h = Monday 2 + Thursday 5.
        $schedule = $this->scheduler->schedule($this->year, $this->date('2026-03-02'), $this->eightHoursADay(), [16.0], ['2026-03-03' => true, '2026-03-04' => true]);

        self::assertTrue($schedule['completed']);
        self::assertEquals($this->date('2026-03-02'), $schedule['items'][0]['startDate']);
        self::assertEquals($this->date('2026-03-05'), $schedule['items'][0]['endDate']);
        self::assertCount(2, $schedule['days']);
    }

    public function testAnItemFittingInOneDayStartsAndEndsOnIt(): void
    {
        $schedule = $this->scheduler->schedule($this->year, $this->date('2026-03-02'), $this->eightHoursADay(), [3.0, 2.0]);

        self::assertEquals($this->date('2026-03-02'), $schedule['items'][0]['startDate']);
        self::assertEquals($this->date('2026-03-02'), $schedule['items'][0]['endDate']);
        self::assertEquals($this->date('2026-03-02'), $schedule['items'][1]['startDate']);
        self::assertEquals($this->date('2026-03-02'), $schedule['items'][1]['endDate']);
    }

    public function testWithoutHoursOnAnyWeekdayNothingCanBePlaced(): void
    {
        $schedule = $this->scheduler->schedule($this->year, $this->date('2026-03-02'), [1 => null, 2 => 0.0, 3 => null, 4 => null, 5 => null], [10.0]);

        self::assertFalse($schedule['completed']);
        self::assertNull($schedule['items'][0]['startDate']);
        self::assertSame([], $schedule['days']);
    }

    public function testDecimalHoursAreSupported(): void
    {
        $schedule = $this->scheduler->schedule($this->year, $this->date('2026-03-02'), $this->eightHoursADay(), [7.5, 1.0]);

        self::assertTrue($schedule['completed']);
        self::assertEquals($this->date('2026-03-02'), $schedule['items'][0]['endDate']);
        self::assertEquals($this->date('2026-03-03'), $schedule['items'][1]['endDate'], 'the 0.5 h left on Monday plus 0.5 h of Tuesday');
        self::assertEquals($this->date('2026-03-02'), $schedule['items'][1]['startDate']);
    }
}
