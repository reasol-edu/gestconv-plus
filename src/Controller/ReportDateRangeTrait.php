<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

/** Lee del query string el rango de fechas (`from`, `to`) de un informe, ordenándolo si llega al revés. */
trait ReportDateRangeTrait
{
    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function parseRange(Request $request): array
    {
        $from = $this->parseDate($request->query->getString('from'));
        $to   = $this->parseDate($request->query->getString('to'));

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
