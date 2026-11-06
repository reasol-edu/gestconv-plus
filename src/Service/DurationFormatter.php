<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/** Da formato legible a una duración en segundos para los informes: «45 min», «5 h 20 min», «3 d 4 h». */
class DurationFormatter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    public function format(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $minutes = intdiv($seconds + 30, 60);
        if ($minutes < 60) {
            return $this->translator->trans('report.duration.minutes', ['%minutes%' => $minutes], 'admin');
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $this->translator->trans('report.duration.hours', ['%hours%' => $hours, '%minutes%' => $minutes % 60], 'admin');
        }

        return $this->translator->trans('report.duration.days', ['%days%' => intdiv($hours, 24), '%hours%' => $hours % 24], 'admin');
    }

    /** Días con un decimal (para las hojas de cálculo); null si no hay dato. */
    public static function days(?int $seconds): ?float
    {
        return $seconds === null ? null : round($seconds / 86400, 1);
    }

    /** Minutos enteros (para las hojas de cálculo); null si no hay dato. */
    public static function minutes(?int $seconds): ?int
    {
        return $seconds === null ? null : intdiv($seconds + 30, 60);
    }
}
