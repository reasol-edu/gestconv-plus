<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\PrintableCalendar;

/** Everything PrintableCalendarPdfBuilder computes for one PrintableCalendar's PDF — the Twig
 * template (templates/utilities/pdf/calendar.html.twig) only lays it out, it computes nothing. */
final readonly class PrintableCalendarRenderData
{
    /**
     * @param list<PrintableCalendarMonth>       $months
     * @param list<PrintableCalendarJourneyLine> $journeySummaries
     */
    public function __construct(
        public PrintableCalendar $calendar,
        public \DateTimeImmutable $effectiveStart,
        public \DateTimeImmutable $effectiveEnd,
        public array $months,
        public array $journeySummaries,
        public PrintableCalendarPdfSizes $sizes,
    ) {}

    /**
     * The journey summary split into the boxes the PDF draws, keeping the by-start-date order:
     * consecutive periods share one box, and each sequence gets a box of its own.
     *
     * @return list<list<PrintableCalendarJourneyLine>>
     */
    public function journeyBoxes(): array
    {
        $boxes   = [];
        $periods = [];
        foreach ($this->journeySummaries as $line) {
            if ($line->items === []) {
                $periods[] = $line;

                continue;
            }
            if ($periods !== []) {
                $boxes[]   = $periods;
                $periods   = [];
            }
            $boxes[] = [$line];
        }
        if ($periods !== []) {
            $boxes[] = $periods;
        }

        return $boxes;
    }
}
