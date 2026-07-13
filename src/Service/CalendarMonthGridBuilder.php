<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sanction;

/**
 * Builds the week-by-week grid (days + sanction segments) for a given month,
 * for the admin calendar (CalendarComponent). Weekends are excluded from the
 * grid since there is no class on Saturday/Sunday.
 */
final class CalendarMonthGridBuilder
{
    public function __construct(
        private readonly CalendarSegmentBuilder $segmentBuilder,
        private readonly GroupColorPalette $colorPalette,
    ) {}

    /**
     * @param list<Sanction> $sanctions
     * @return list<array{days: list<\DateTimeImmutable>, segments: list<array<string, mixed>>, maxLane: int}>
     */
    public function build(int $year, int $month, array $sanctions): array
    {
        $sanctionsById = [];
        foreach ($sanctions as $sanction) {
            $sanctionsById[$sanction->getId()->toRfc4122()] = $sanction;
        }

        $firstDay  = (new \DateTimeImmutable())->setDate($year, $month, 1)->setTime(0, 0, 0);
        $lastDay   = $firstDay->modify('last day of this month');
        $startDow  = (int) $firstDay->format('N');
        $gridStart = $firstDay->modify('-' . ($startDow - 1) . ' days');
        $endDow    = (int) $lastDay->format('N');
        $gridEnd   = $lastDay->modify('+' . (7 - $endDow) . ' days');

        $weeks  = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $days = [];
            $d    = $cursor;
            for ($i = 0; $i < 7; $i++) {
                // No hay clase en sábado y domingo (N: 6 y 7): no se muestran en el calendario.
                if ((int) $d->format('N') <= 5) {
                    $days[] = $d;
                }
                $d = $d->modify('+1 day');
            }

            $weekStart = $days[0];
            $weekEnd   = $days[count($days) - 1];

            $events = [];
            foreach ($sanctions as $sanction) {
                $start = $sanction->getEffectiveFrom();
                if ($start === null) {
                    continue;
                }
                $end = $sanction->getEffectiveTo() ?? $start;
                if ($end < $weekStart || $start > $weekEnd) {
                    continue;
                }
                $events[] = ['id' => $sanction->getId()->toRfc4122(), 'start' => $start, 'end' => $end];
            }

            $layout   = $this->segmentBuilder->build($events, $days);
            $segments = array_map(function (array $segment) use ($sanctionsById): array {
                $sanction = $sanctionsById[$segment['id']];
                $group    = $sanction->getGroup();

                return [
                    'startCol' => $segment['startCol'],
                    'span'     => $segment['span'],
                    'lane'     => $segment['lane'],
                    'label'    => $sanction->getStudent()->getName()->full() . ' · ' . $group->getName(),
                    'details'  => $sanction->getCalendarLabel() ?? trim(strip_tags($sanction->getDetails())),
                    'color'    => $this->colorPalette->colorFor($group->getId()->toRfc4122()),
                ];
            }, $layout['segments']);

            $weeks[] = ['days' => $days, 'segments' => $segments, 'maxLane' => $layout['maxLane']];
            $cursor  = $cursor->modify('+7 days');
        }

        return $weeks;
    }
}
