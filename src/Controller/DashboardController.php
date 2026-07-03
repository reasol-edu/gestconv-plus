<?php

namespace App\Controller;

use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use App\Repository\StudentRepository;
use App\Service\PendingNotificationQueue;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StudentRepository $studentRepository,
        private readonly IncidentReportRepository $incidentRepository,
        private readonly SanctionRepository $sanctionRepository,
        private readonly GroupRepository $groupRepository,
        private readonly PendingNotificationQueue $pendingNotificationQueue,
    ) {}

    #[Route('/', name: 'app_dashboard')]
    public function index(): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $year   = $this->tenantContext->getViewYear($centre);
        $user   = $this->getUser();
        $viewer = $user instanceof Teacher ? $user : null;

        if ($year === null || $viewer === null) {
            return $this->render('dashboard/index.html.twig', [
                'studentCount'         => $this->studentRepository->countByActiveYear($centre, $viewer, $year),
                'reportCount30d'       => 0,
                'activeSanctionsCount' => 0,
                'pendingQueue'         => ['reports' => [], 'sanctions' => [], 'total' => 0],
                'recentReports'        => [],
                'hasFullAccess'        => false,
                'topStudents'          => [],
                'tutoredGroups'        => [],
            ]);
        }

        $hasFullAccess = $viewer->isAdmin()
            || $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);

        $topStudents   = [];
        $tutoredGroups = [];

        if ($hasFullAccess) {
            $topStudents = $this->sanctionRepository->findStudentStatsForCentre($centre, '', 1, 5)['rows'];
        } else {
            $groups     = $this->groupRepository->findTutoredByActiveYear($centre, $viewer, $year);
            $groupCounts = $this->groupRepository->findCountsByAcademicYear($year, $groups);
            foreach ($groups as $group) {
                $tutoredGroups[] = [
                    'group'    => $group,
                    'students' => $groupCounts[$group->getId()->toRfc4122()]['students'] ?? 0,
                ];
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'studentCount'         => $this->studentRepository->countByActiveYear($centre, $viewer, $year),
            'reportCount30d'       => $this->incidentRepository->countRecentByCentre($centre, $viewer),
            'activeSanctionsCount' => $this->sanctionRepository->countActiveByCentre($centre, $viewer, new \DateTimeImmutable()),
            'pendingQueue'         => $this->pendingNotificationQueue->forViewer($centre, $viewer),
            'recentReports'        => $this->incidentRepository->createFilteredQuery($centre, $viewer)->setMaxResults(6)->getResult(),
            'hasFullAccess'        => $hasFullAccess,
            'topStudents'          => $topStudents,
            'tutoredGroups'        => $tutoredGroups,
        ]);
    }
}
