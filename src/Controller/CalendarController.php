<?php

namespace App\Controller;

use App\Entity\Sanction;
use App\Repository\SanctionRepository;
use App\Service\CalendarBoardBuilder;
use App\Service\KioskMode;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CalendarController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/calendario', name: 'app_calendar')]
    public function index(): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        return $this->render('calendar/index.html.twig');
    }

    #[Route('/calendario/tablon', name: 'app_calendar_board')]
    public function board(
        SanctionRepository $sanctionRepository,
        CalendarBoardBuilder $boardBuilder,
        KioskMode $kioskMode,
    ): Response {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $academicYear = $this->tenantContext->getViewYear($centre);
        $items        = $academicYear !== null
            ? $this->toBoardItems($sanctionRepository->findWithDatesForAcademicYear($academicYear))
            : [];

        $today = new \DateTimeImmutable('today');

        // Activar el modo tablón bloquea el resto de la aplicación en esta
        // sesión del navegador: solo se podrá salir cerrando sesión.
        $kioskMode->activate();

        return $this->render('calendar/board.html.twig', [
            'weeks' => [
                ['label' => 'board_this_week', 'days' => $boardBuilder->build($items, $this->weekdaysOf($today))],
                ['label' => 'board_next_week', 'days' => $boardBuilder->build($items, $this->weekdaysOf($today->modify('+7 days')))],
            ],
            'today' => $today,
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
                'details'   => trim(strip_tags($sanction->getDetails())),
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
