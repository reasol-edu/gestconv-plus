<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Sanction;
use App\Repository\SanctionRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\AppSettings;
use App\Service\BoardTodayBuilder;
use App\Service\BoardTodayReport;
use App\Service\CalendarBoardBuilder;
use App\Service\KioskMode;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CalendarController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AppSettings $appSettings,
    ) {}

    #[Route('/calendario', name: 'app_calendar')]
    public function index(Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $isAdmin = $this->isGranted(EducationalCentreVoter::SECTION, $centre);
        $tab     = $isAdmin && $request->query->getString('tab') === 'absences' ? 'absences' : 'sanctions';

        return $this->render('calendar/index.html.twig', [
            'isAdmin' => $isAdmin,
            'tab'     => $tab,
        ]);
    }

    #[Route('/calendario/tablon', name: 'app_calendar_board')]
    public function board(
        SanctionRepository $sanctionRepository,
        CalendarBoardBuilder $boardBuilder,
        BoardTodayBuilder $boardTodayBuilder,
        KioskMode $kioskMode,
    ): Response {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $academicYear = $this->tenantContext->getViewYear($centre);
        $items        = $academicYear !== null
            ? $this->toBoardItems($sanctionRepository->findWithDatesForAcademicYear($academicYear))
            : [];

        $today = new \DateTimeImmutable('today');

        // Activar el modo tablón bloquea el resto de la aplicación en esta
        // sesión del navegador: solo se podrá salir cerrando sesión.
        $kioskMode->activate();

        $todaySeconds   = $this->appSettings->getInt('board.today_seconds');
        $currentSeconds = $this->appSettings->getInt('board.current_week_seconds');
        $nextSeconds    = $this->appSettings->getInt('board.next_week_seconds');
        $theme          = $this->appSettings->get('board.theme');

        $todayReport = $academicYear !== null
            ? $boardTodayBuilder->build($academicYear, $today)
            : new BoardTodayReport($today, [], [], []);

        // Un valor de 0 en la duración de una pantalla la omite. Si todas
        // las pantallas están omitidas, se muestra solo "Hoy" sin rotación.
        $screens = [];
        if ($todaySeconds > 0) {
            $screens[] = ['type' => 'today', 'label' => 'board_today', 'seconds' => $todaySeconds, 'report' => $todayReport];
        }
        if ($currentSeconds > 0) {
            $screens[] = ['type' => 'week', 'label' => 'board_this_week', 'seconds' => $currentSeconds, 'days' => $boardBuilder->build($items, $this->weekdaysOf($today))];
        }
        if ($nextSeconds > 0) {
            $screens[] = ['type' => 'week', 'label' => 'board_next_week', 'seconds' => $nextSeconds, 'days' => $boardBuilder->build($items, $this->weekdaysOf($today->modify('+7 days')))];
        }
        if ($screens === []) {
            $screens[] = ['type' => 'today', 'label' => 'board_today', 'seconds' => 0, 'report' => $todayReport];
        }

        return $this->render('calendar/board.html.twig', [
            'screens'    => $screens,
            'today'      => $today,
            'theme'      => $theme,
            'centreName' => $centre->getName(),
        ]);
    }

    /**
     * @param list<Sanction> $sanctions
     *
     * @return list<array{groupId: string, groupName: string, student: string, details: string, from: \DateTimeImmutable, to: ?\DateTimeImmutable}>
     */
    private function toBoardItems(array $sanctions): array
    {
        $items = [];
        foreach ($sanctions as $sanction) {
            $from = $sanction->getEffectiveFrom();
            if ($from === null) {
                continue;
            }

            $group    = $sanction->getGroup();
            $items[] = [
                'groupId'   => $group->getId()->toRfc4122(),
                'groupName' => $group->getName(),
                'student'   => $sanction->getStudent()->getName()->full(),
                'details'   => $sanction->getCalendarLabel() ?? trim(strip_tags($sanction->getDetails())),
                'from'      => $from,
                'to'        => $sanction->getEffectiveTo(),
            ];
        }

        return $items;
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function weekdaysOf(\DateTimeImmutable $reference): array
    {
        $monday = $reference->modify('monday this week');

        return array_map(
            static fn (int $offset): \DateTimeImmutable => $monday->modify("+{$offset} days"),
            range(0, 4),
        );
    }
}
