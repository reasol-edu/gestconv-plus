<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Repository\SanctionRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\AppSettings;
use App\Service\BoardTodayBuilder;
use App\Service\BoardTodayReport;
use App\Service\CalendarBoardBuilder;
use App\Service\DayDetailBuilder;
use App\Service\KioskMode;
use App\Service\NonWorkingDayChecker;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class CalendarController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AppSettings $appSettings,
        private readonly NonWorkingDayChecker $nonWorkingDayChecker,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/calendario', name: 'app_calendar')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $isAdmin      = $this->isGranted(EducationalCentreVoter::SECTION, $centre);
        $requestedTab = $request->query->getString('tab');
        $tab          = $isAdmin && $requestedTab === 'events' ? 'events' : 'calendar';

        return $this->render('calendar/index.html.twig', [
            'centre'  => $centre,
            'isAdmin' => $isAdmin,
            'tab'     => $tab,
        ]);
    }

    #[Route('/calendario/dia/{date}', name: 'app_calendar_day', requirements: ['date' => '\d{4}-\d{2}-\d{2}'])]
    public function day(string $date, DayDetailBuilder $dayDetailBuilder, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $parsedDate = $this->parseDate($date);
        if ($parsedDate === null) {
            throw $this->createNotFoundException();
        }

        $academicYear = $this->tenantContext->getViewYear($centre);
        if ($academicYear === null) {
            throw $this->createNotFoundException();
        }

        $isAdmin = $this->isGranted(EducationalCentreVoter::SECTION, $centre);
        $user    = $this->getUser();
        $viewer  = $user instanceof Teacher ? $user : null;

        $report = $dayDetailBuilder->build($academicYear, $viewer, $isAdmin, $parsedDate);

        return $this->render('calendar/day.html.twig', [
            'centre'   => $centre,
            'date'     => $parsedDate,
            'isAdmin'  => $isAdmin,
            'report'   => $report,
            'prevDate' => $parsedDate->modify('-1 day')->format('Y-m-d'),
            'nextDate' => $parsedDate->modify('+1 day')->format('Y-m-d'),
        ]);
    }

    #[Route('/calendario/tablon', name: 'app_calendar_board')]
    public function board(
        SanctionRepository $sanctionRepository,
        CalendarBoardBuilder $boardBuilder,
        BoardTodayBuilder $boardTodayBuilder,
        KioskMode $kioskMode,
        #[CurrentCentre] EducationalCentre $centre,
    ): Response {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $academicYear = $this->tenantContext->getViewYear($centre);
        $items        = $academicYear !== null
            ? $this->toBoardItems($sanctionRepository->findWithDatesForAcademicYear($academicYear))
            : [];

        $today = $this->clock->now()->setTime(0, 0, 0);

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

        $nextWeekStart  = $today->modify('+7 days');
        $nonWorkingDays = $academicYear !== null
            ? $this->nonWorkingDaysMap($academicYear, [...$this->weekdaysOf($today), ...$this->weekdaysOf($nextWeekStart)])
            : [];

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
            $screens[] = ['type' => 'week', 'label' => 'board_next_week', 'seconds' => $nextSeconds, 'days' => $boardBuilder->build($items, $this->weekdaysOf($nextWeekStart))];
        }
        if ($screens === []) {
            $screens[] = ['type' => 'today', 'label' => 'board_today', 'seconds' => 0, 'report' => $todayReport];
        }

        return $this->render('calendar/board.html.twig', [
            'screens'        => $screens,
            'today'          => $today,
            'theme'          => $theme,
            'centreName'     => $centre->getName(),
            'nonWorkingDays' => $nonWorkingDays,
        ]);
    }

    /**
     * @param list<\DateTimeImmutable> $days
     *
     * @return array<string, string>
     */
    private function nonWorkingDaysMap(AcademicYear $academicYear, array $days): array
    {
        $map = [];
        foreach ($days as $day) {
            if (!$this->nonWorkingDayChecker->isNonWorkingDay($academicYear, $day)) {
                continue;
            }

            $map[$day->format('Y-m-d')] = $this->nonWorkingDayChecker->descriptionFor($academicYear, $day)
                ?? $this->translator->trans('board_day_non_working', [], 'calendar');
        }

        return $map;
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

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false ? $date : null;
    }
}
