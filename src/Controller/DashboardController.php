<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use App\Repository\StudentRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\SanctionVoter;
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
        private readonly TeacherRepository $teacherRepository,
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
                'groupCount'           => 0,
                'teacherCount'         => 0,
                'reportCount30d'       => 0,
                'activeSanctionsCount' => 0,
                'pendingQueue'         => ['reports' => [], 'sanctions' => [], 'total' => 0],
                'recentReports'        => [],
                'hasFullAccess'        => false,
                'topStudents'          => [],
                'tutoredGroups'        => [],
                'sanctionableCount'    => 0,
                'hasTeachingGroups'    => false,
                'thisWeekSanctions'    => [],
                'nextWeekSanctions'    => [],
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

        $canSanction = $this->isGranted(SanctionVoter::CREATE, $centre);

        $today      = new \DateTimeImmutable('today');
        $dow        = (int) $today->format('N'); // 1 = lunes … 7 = domingo
        $thisMonday = $today->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
        $thisSunday = $thisMonday->modify('+6 days')->setTime(23, 59, 59);
        $nextMonday = $thisMonday->modify('+7 days');
        $nextSunday = $nextMonday->modify('+6 days')->setTime(23, 59, 59);

        $hasTeachingGroups = $this->groupRepository->hasTeachingGroupsInYear($centre, $viewer, $year);
        $thisWeekSanctions = $hasTeachingGroups
            ? $this->sanctionRepository->findActiveForTeacherInDateRange($centre, $viewer, $year, $thisMonday, $thisSunday)
            : [];
        $nextWeekSanctions = $hasTeachingGroups
            ? $this->sanctionRepository->findActiveForTeacherInDateRange($centre, $viewer, $year, $nextMonday, $nextSunday)
            : [];

        return $this->render('dashboard/index.html.twig', [
            'studentCount'         => $this->studentRepository->countByActiveYear($centre, $viewer, $year),
            'groupCount'           => $this->groupRepository->countByActiveYearOfCentre($centre, $year),
            'teacherCount'         => $this->teacherRepository->countByAcademicYear($year),
            'reportCount30d'       => $this->incidentRepository->countRecentByCentre($centre, $viewer, $year, 30),
            'activeSanctionsCount' => $this->sanctionRepository->countActiveByCentre($centre, $viewer, $today, $year),
            'pendingQueue'         => $this->pendingNotificationQueue->forViewer($centre, $viewer, $year),
            'recentReports'        => $this->incidentRepository->createFilteredQuery($centre, $viewer, $year, [])->setMaxResults(6)->getResult(),
            'hasFullAccess'        => $hasFullAccess,
            'topStudents'          => $topStudents,
            'tutoredGroups'        => $tutoredGroups,
            'sanctionableCount'    => $canSanction ? $this->sanctionRepository->countSanctionableByCentre($centre) : 0,
            'hasTeachingGroups'    => $hasTeachingGroups,
            'thisWeekSanctions'    => $thisWeekSanctions,
            'nextWeekSanctions'    => $nextWeekSanctions,
        ]);
    }
}
