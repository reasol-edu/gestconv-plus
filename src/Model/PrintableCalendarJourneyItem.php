<?php

declare(strict_types=1);

namespace App\Model;

/** One item of a sequence in the journey summary: «UD2 — 11/11/2026 - 18/11/2026 · 10 horas». */
final readonly class PrintableCalendarJourneyItem
{
    /** @param string $hoursLabel already-translated and pluralised («10 horas», «1 hora») */
    public function __construct(
        public string $description,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public string $hoursLabel,
    ) {}
}
