<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The "jornadas" summary shown above the calendar for one period with showJourneySummary set, or
 * for one sequence: then $description is the sequence's name, $items its items, one line each, and
 * $hoursLines holds just the total.
 */
final readonly class PrintableCalendarJourneyLine
{
    /**
     * @param list<string>                       $hoursLines already-translated ("47 jornadas de 8 horas", "y 1 de 4 horas"...); empty for a plain date-range period
     * @param list<PrintableCalendarJourneyItem> $items      the items of a sequence, in order; empty for a period
     */
    public function __construct(
        public string $description,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public array $hoursLines,
        public array $items = [],
    ) {}
}
