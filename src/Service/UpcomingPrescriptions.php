<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Partes y sanciones visibles para un docente que prescribirán dentro del plazo de aviso (mismo
 * criterio que las tarjetas del panel y los correos de aviso), los más cercanos a prescribir primero.
 * Las sanciones solo prescriben si "notifications.sanction_auto_prescribe_days" es mayor que 0.
 */
class UpcomingPrescriptions
{
    public function __construct(
        private readonly AppSettingsInterface $settings,
        private readonly IncidentReportRepository $reports,
        private readonly SanctionRepository $sanctions,
        private readonly ClockInterface $clock,
    ) {}

    /** @return list<array{type: 'report'|'sanction', report: ?IncidentReport, sanction: ?Sanction, daysLeft: int}> */
    public function forViewer(EducationalCentre $centre, Teacher $viewer, AcademicYear $year): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $rows  = [];

        $autoPrescribeDays = $this->settings->getForCentre('notifications.report_auto_prescribe_days', $centre);
        $warningDays       = $this->settings->getForTeacherInCentre('notifications.report_prescription_warning_days', $viewer, $centre);
        if (is_int($autoPrescribeDays) && $autoPrescribeDays > 0 && is_int($warningDays) && $warningDays > 0) {
            $cutoff = $today->modify('-' . max(0, $autoPrescribeDays - $warningDays) . ' days');
            foreach ($this->reports->findPendingPrescriptionForViewer($centre, $viewer, $year, $cutoff) as $report) {
                $age    = (int) $report->getOccurredAt()->setTime(0, 0)->diff($today)->format('%r%a');
                $rows[] = ['type' => 'report', 'report' => $report, 'sanction' => null, 'daysLeft' => max(0, $autoPrescribeDays - $age)];
            }
        }

        $sanctionDays        = $this->settings->getForCentre('notifications.sanction_auto_prescribe_days', $centre);
        $sanctionWarningDays = $this->settings->getForTeacherInCentre('notifications.sanction_prescription_warning_days', $viewer, $centre);
        if (is_int($sanctionDays) && $sanctionDays > 0 && is_int($sanctionWarningDays) && $sanctionWarningDays > 0) {
            $cutoff = $today->modify('-' . max(0, $sanctionDays - $sanctionWarningDays) . ' days');
            foreach ($this->sanctions->findPendingPrescriptionForViewer($centre, $viewer, $year, $cutoff) as $sanction) {
                $age    = (int) $sanction->getCreatedAt()->setTime(0, 0)->diff($today)->format('%r%a');
                $rows[] = ['type' => 'sanction', 'report' => null, 'sanction' => $sanction, 'daysLeft' => max(0, $sanctionDays - $age)];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a['daysLeft'] <=> $b['daysLeft']);

        return $rows;
    }
}
