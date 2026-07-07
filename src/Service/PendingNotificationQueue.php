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
use App\Security\Voter\IncidentReportVoter;
use App\Security\Voter\SanctionVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Resolves the partes/sanciones pendientes de notificar that a given teacher can actually act on
 * (not just view), so the dashboard and the header bell share the exact same computation.
 */
final class PendingNotificationQueue
{
    public function __construct(
        private readonly IncidentReportRepository $reports,
        private readonly SanctionRepository $sanctions,
        private readonly AuthorizationCheckerInterface $authChecker,
    ) {}

    /** @return array{reports: list<IncidentReport>, sanctions: list<Sanction>, total: int} */
    public function forViewer(EducationalCentre $centre, Teacher $viewer, AcademicYear $year): array
    {
        $reports = array_values(array_filter(
            $this->reports->findPendingNotification($centre, $viewer, $year),
            fn (IncidentReport $r): bool => $this->authChecker->isGranted(IncidentReportVoter::NOTIFY, $r),
        ));
        $sanctions = array_values(array_filter(
            $this->sanctions->findPendingNotification($centre, $viewer, $year),
            fn (Sanction $s): bool => $this->authChecker->isGranted(SanctionVoter::NOTIFY, $s),
        ));

        return [
            'reports'   => $reports,
            'sanctions' => $sanctions,
            'total'     => count($reports) + count($sanctions),
        ];
    }
}
