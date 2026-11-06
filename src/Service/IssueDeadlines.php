<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Issue;
use App\Entity\IssuePriority;

/**
 * Fecha límite de resolución de una incidencia: los plazos de su prioridad (recomendado y máximo) contados en días
 * lectivos desde el día siguiente a que se asigna (no cuentan los fines de semana ni los días no lectivos del curso). Un plazo de 0 días no
 * procede y deja la fecha vacía. Las fechas se guardan en la incidencia: así se pueden filtrar y ordenar, y cambiar después
 * los plazos de la prioridad no mueve las de las incidencias que ya la tenían.
 */
final class IssueDeadlines
{
    public function __construct(
        private readonly NonWorkingDayChecker $calendar,
    ) {}

    public function apply(Issue $issue, ?IssuePriority $priority, \DateTimeImmutable $from): void
    {
        $issue->setRecommendedDue($this->due($issue, $priority?->getRecommendedDays() ?? 0, $from));
        $issue->setMaxDue($this->due($issue, $priority?->getMaxDays() ?? 0, $from));
    }

    private function due(Issue $issue, int $days, \DateTimeImmutable $from): ?\DateTimeImmutable
    {
        if ($days <= 0) {
            return null;
        }

        // Se cuenta desde el día siguiente al de la asignación: con «1 día», se asigna el lunes y vence el martes.
        return $this->calendar->addSchoolDays($issue->getAcademicYear(), $from->setTime(0, 0)->modify('+1 day'), $days);
    }
}
