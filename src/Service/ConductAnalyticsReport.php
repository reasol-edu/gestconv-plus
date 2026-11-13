<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Datos de la analítica de convivencia de un centro en un rango de fechas: cifras clave y las series de cada gráfico.
 * Todas las series están ya ordenadas y rellenas (los meses o las horas sin partes llevan 0) para pintarse tal cual.
 */
final readonly class ConductAnalyticsReport
{
    /**
     * @param list<array{key: string, year: int, month: int, contrary: int, serious: int, sanctions: int}> $months
     * @param list<array{day: int, count: int}>                                                               $weekdays Lunes a viernes siempre; sábado y domingo solo si hay partes
     * @param list<array{hour: int, count: int}>                                                              $hours    Desde la primera hasta la última hora con partes
     * @param list<array{name: string, category: string, serious: bool, count: int}>                         $behaviours Las más registradas
     * @param list<array{name: string, reports: int, students: int, per100: float|null}>                     $courses
     * @param list<array{name: string, course: string, reports: int, students: int, per100: float|null}>    $groups   Los de más partes
     * @param list<array{bucket: string, students: int, reports: int}>                                       $concentration Estudiantes según su número de partes (1, 2, 3, 4, 5 o más)
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public int $totalReports,
        public int $seriousReports,
        public int $students,
        public int $sanctionedReports,
        public int $expelledReports,
        public int $sanctions,
        public int $totalBehaviours,
        public int $repeatStudents,
        public int $repeatReports,
        public array $months,
        public array $weekdays,
        public array $hours,
        public array $behaviours,
        public array $courses,
        public array $groups,
        public array $concentration,
    ) {}

    public function contraryReports(): int
    {
        return $this->totalReports - $this->seriousReports;
    }

    public function isEmpty(): bool
    {
        return $this->totalReports === 0 && $this->sanctions === 0;
    }

    /** Porcentaje (0-100, un decimal) de los partes que acumulan los estudiantes con 3 o más; null si no hay partes. */
    public function repeatShare(): ?float
    {
        return $this->totalReports === 0 ? null : round($this->repeatReports / $this->totalReports * 100, 1);
    }
}
