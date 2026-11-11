<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;

/**
 * Lays the items of a calendar sequence out over the calendar: ONE walk of the days from the
 * sequence's start date for the sum of every item's hours (WeekdayHoursWalker, the same rules as
 * the periods and the date calculator), then split by cumulative hours. An item that ends mid-day
 * therefore leaves the rest of that day to the next one, so a single day can hold two items.
 *
 * Works on plain values (not entities) so the same code serves the PDF (PrintableCalendarPdfBuilder)
 * and anything that has to preview an unsaved sequence.
 *
 * @phpstan-type ScheduledItem array{startDate: ?\DateTimeImmutable, endDate: ?\DateTimeImmutable, hours: float, days: list<array{date: \DateTimeImmutable, hours: float}>}
 * @phpstan-type ScheduledDay array{date: \DateTimeImmutable, hours: float, item: int}
 * @phpstan-type Schedule array{items: list<ScheduledItem>, days: list<ScheduledDay>, completed: bool}
 */
final class PrintableCalendarSequenceScheduler
{
    private const float EPSILON = 0.0001;

    public function __construct(
        private readonly WeekdayHoursWalker $dayWalker,
    ) {}

    /**
     * @param array<int, ?float> $weekdayHours hours for ISO weekday 1 (Monday) .. 5 (Friday)
     * @param list<float>        $itemHours    hours of each item, in order
     *
     * @return Schedule `items` has one entry per item (dates null if it got no day at all); `days`
     *                  is every day the sequence occupies with its total hours, and the index of
     *                  the item that "owns" it — the one with most hours that day (on a tie, the
     *                  one starting that day); `completed` is false when the hours couldn't all be
     *                  placed (no weekday with hours, or the walker's cap was hit)
     */
    public function schedule(AcademicYear $year, \DateTimeImmutable $start, array $weekdayHours, array $itemHours): array
    {
        $total = array_sum($itemHours);
        if ($itemHours === [] || $total <= 0.0) {
            return ['items' => array_map(static fn (float $hours): array => ['startDate' => null, 'endDate' => null, 'hours' => 0.0, 'days' => []], $itemHours), 'days' => [], 'completed' => $itemHours !== []];
        }

        $walk = $this->dayWalker->walk($year, $start, true, $weekdayHours, $total);

        /** @var array<int, list<array{date: \DateTimeImmutable, hours: float}>> $itemDays what each item gets, day by day */
        $itemDays  = [];
        $index     = 0;
        $remaining = $itemHours[0];
        $days      = [];
        foreach ($walk['days'] as $day) {
            $left    = $day['hours'];
            $owner   = $index;
            $ownerAt = -1.0;
            while ($left > self::EPSILON && isset($itemHours[$index])) {
                $take = min($left, $remaining);
                if ($take > self::EPSILON) {
                    $itemDays[$index][] = ['date' => $day['date'], 'hours' => $take];
                    // >= : on a tie the later item, the one starting, wins the day.
                    if ($take >= $ownerAt) {
                        $owner   = $index;
                        $ownerAt = $take;
                    }
                }
                $left      -= $take;
                $remaining -= $take;
                if ($remaining <= self::EPSILON) {
                    ++$index;
                    $remaining = $itemHours[$index] ?? 0.0;
                }
            }
            $days[] = ['date' => $day['date'], 'hours' => $day['hours'], 'item' => $owner];
        }

        $items = [];
        foreach (array_keys($itemHours) as $i) {
            $own     = $itemDays[$i] ?? [];
            $items[] = [
                'startDate' => $own === [] ? null : $own[0]['date'],
                'endDate'   => $own === [] ? null : $own[array_key_last($own)]['date'],
                'hours'     => array_sum(array_column($own, 'hours')),
                'days'      => $own,
            ];
        }

        return ['items' => $items, 'days' => $days, 'completed' => $walk['completed']];
    }
}
