<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Una fila del informe de incidencias: las cifras de un conjunto de incidencias (un tipo, una prioridad, un mes…) o el
 * total del informe. Los tiempos van en segundos y solo cuentan las incidencias cerradas (o gestionadas, en el caso de la
 * primera gestión).
 */
final readonly class IssueStatsRow
{
    public function __construct(
        public string $label,
        public int $registered,
        /** Abiertas o en proceso. */
        public int $open,
        public int $solved,
        public int $notApplicable,
        public int $duplicate,
        /** Abiertas cuyo plazo máximo ya ha pasado. */
        public int $overdue,
        public ?int $avgCloseSeconds,
        public ?int $medianCloseSeconds,
        /** Tiempo medio hasta la primera gestión (prioridad, estado o asignación). */
        public ?int $avgFirstManagementSeconds,
        /** Cerradas con plazo máximo, y de ellas, las cerradas a tiempo. */
        public int $closedWithMaxDue,
        public int $closedOnTimeMax,
        /** Cerradas con plazo recomendado, y de ellas, las cerradas a tiempo. */
        public int $closedWithRecommendedDue,
        public int $closedOnTimeRecommended,
    ) {}

    public function closed(): int
    {
        return $this->solved + $this->notApplicable + $this->duplicate;
    }

    /** Las que no se resolvieron en plazo: cerradas fuera de plazo máximo y abiertas ya vencidas. */
    public function late(): int
    {
        return $this->overdue + ($this->closedWithMaxDue - $this->closedOnTimeMax);
    }

    /** Porcentaje (0–100, un decimal) de $part sobre $whole; null si $whole es 0. */
    public static function percent(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    public function closedPercent(): ?float
    {
        return self::percent($this->closed(), $this->registered);
    }

    public function onTimeMaxPercent(): ?float
    {
        return self::percent($this->closedOnTimeMax, $this->closedWithMaxDue);
    }

    public function onTimeRecommendedPercent(): ?float
    {
        return self::percent($this->closedOnTimeRecommended, $this->closedWithRecommendedDue);
    }
}
