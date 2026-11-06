<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Cifras de un conjunto de salidas del aula: cuántas, de cuántos estudiantes y grupos, y cuánto tiempo estuvieron fuera.
 * El tiempo solo cuenta las salidas que ya han regresado.
 */
final readonly class ExitTotals
{
    public function __construct(
        public int $exits,
        public int $students,
        public int $groups,
        /** Salidas con regreso registrado. */
        public int $returned,
        /** Suma de los segundos fuera de las salidas con regreso. */
        public int $secondsTotal,
        /** La salida más larga, en segundos. */
        public int $secondsMax,
    ) {}

    /** Salidas sin regreso registrado. */
    public function notReturned(): int
    {
        return $this->exits - $this->returned;
    }

    /** Tiempo medio fuera, en segundos (null si ninguna ha regresado). */
    public function averageSeconds(): ?int
    {
        return $this->returned > 0 ? intdiv($this->secondsTotal, $this->returned) : null;
    }
}
