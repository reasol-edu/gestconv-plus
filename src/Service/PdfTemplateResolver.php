<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;

/**
 * Resuelve qué plantilla PDF de fondo debe usarse para un informe concreto:
 * la plantilla específica de ese tipo de informe si existe, o si no la
 * plantilla general de la orientación que ese informe necesita.
 */
final class PdfTemplateResolver
{
    public function __construct(
        private readonly AppSettingsInterface $settings,
    ) {}

    /**
     * Un informe sin ajuste de plantilla propio recibe siempre la general de su orientación.
     * «printable_calendar» (Utilidades › Generador de calendarios) es el único tipo que se genera en
     * cualquiera de las dos orientaciones (la elige el profesor en cada calendario), así que, a
     * diferencia del resto, tiene un ajuste específico por orientación y no uno compartido.
     *
     * @param 'incident'|'sanction'|'group_stats'|'exit_stats'|'issue_stats'|'analytics'|'guard_duty'|'school_event'|'issue'|'student'|'printable_calendar' $reportType
     */
    public function resolve(string $reportType, string $orientation, EducationalCentre $centre): ?ResolvedSettingFile
    {
        $suffix      = $orientation === 'L' ? 'landscape' : 'portrait';
        $specificKey = $reportType === 'printable_calendar'
            ? "reports.{$reportType}_pdf_template_{$suffix}"
            : "reports.{$reportType}_pdf_template";
        $specific    = $this->settings->getFileForCentre($specificKey, $centre);
        if ($specific !== null) {
            return $specific;
        }

        $generalKey = $orientation === 'L' ? 'reports.pdf_template_landscape' : 'reports.pdf_template_portrait';

        return $this->settings->getFileForCentre($generalKey, $centre);
    }
}
