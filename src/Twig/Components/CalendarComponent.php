<?php

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Sanction;
use App\Repository\SanctionRepository;
use App\Service\CalendarSegmentBuilder;
use App\Service\GroupColorPalette;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class CalendarComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public int $year = 0;

    #[LiveProp(writable: true)]
    public int $month = 0;

    /** @var list<Sanction>|null */
    private ?array $sanctionsCache = null;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly SanctionRepository $sanctionRepository,
        private readonly GroupColorPalette $colorPalette,
        private readonly CalendarSegmentBuilder $segmentBuilder,
    ) {}

    public function mount(): void
    {
        $today = new \DateTimeImmutable();
        if ($this->year < 2000 || $this->year > 2100) {
            $this->year = (int) $today->format('Y');
        }
        if ($this->month < 1 || $this->month > 12) {
            $this->month = (int) $today->format('n');
        }
    }

    #[LiveAction]
    public function previousMonth(): void
    {
        $d = (new \DateTimeImmutable())->setDate($this->year, $this->month, 1)->modify('-1 month');
        $this->year  = (int) $d->format('Y');
        $this->month = (int) $d->format('n');
    }

    #[LiveAction]
    public function nextMonth(): void
    {
        $d = (new \DateTimeImmutable())->setDate($this->year, $this->month, 1)->modify('+1 month');
        $this->year  = (int) $d->format('Y');
        $this->month = (int) $d->format('n');
    }

    #[LiveAction]
    public function goToday(): void
    {
        $today       = new \DateTimeImmutable();
        $this->year  = (int) $today->format('Y');
        $this->month = (int) $today->format('n');
    }

    public function getCentre(): ?EducationalCentre
    {
        return $this->tenantContext->getSelectedCentre();
    }

    public function getMonthLabel(): string
    {
        return $this->translator->trans('month.' . $this->month, [], 'calendar') . ' ' . $this->year;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWeeks(): array
    {
        $centre       = $this->tenantContext->getSelectedCentre();
        $academicYear = $centre !== null ? $this->tenantContext->getViewYear($centre) : null;
        if ($centre === null || $academicYear === null) {
            return [];
        }

        $sanctions   = $this->getSanctionsForYear($academicYear);
        $sanctionsById = [];
        foreach ($sanctions as $sanction) {
            $sanctionsById[$sanction->getId()->toRfc4122()] = $sanction;
        }

        $firstDay  = (new \DateTimeImmutable())->setDate($this->year, $this->month, 1)->setTime(0, 0, 0);
        $lastDay   = $firstDay->modify('last day of this month');
        $startDow  = (int) $firstDay->format('N');
        $gridStart = $firstDay->modify('-' . ($startDow - 1) . ' days');
        $endDow    = (int) $lastDay->format('N');
        $gridEnd   = $lastDay->modify('+' . (7 - $endDow) . ' days');

        $weeks  = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $days = [];
            $d    = $cursor;
            for ($i = 0; $i < 7; $i++) {
                // No hay clase en sábado y domingo (N: 6 y 7): no se muestran en el calendario.
                if ((int) $d->format('N') <= 5) {
                    $days[] = $d;
                }
                $d = $d->modify('+1 day');
            }

            $weekStart = $days[0];
            $weekEnd   = $days[count($days) - 1];

            $events = [];
            foreach ($sanctions as $sanction) {
                $start = $sanction->getEffectiveFrom();
                if ($start === null) {
                    continue;
                }
                $end = $sanction->getEffectiveTo() ?? $start;
                if ($end < $weekStart || $start > $weekEnd) {
                    continue;
                }
                $events[] = ['id' => $sanction->getId()->toRfc4122(), 'start' => $start, 'end' => $end];
            }

            $layout   = $this->segmentBuilder->build($events, $days);
            $segments = array_map(function (array $segment) use ($sanctionsById): array {
                $sanction = $sanctionsById[$segment['id']];
                $group    = $sanction->getGroup();

                return [
                    'startCol' => $segment['startCol'],
                    'span'     => $segment['span'],
                    'lane'     => $segment['lane'],
                    'label'    => $sanction->getStudent()->getName()->full() . ' · ' . $group->getName(),
                    'details'  => trim(strip_tags($sanction->getDetails())),
                    'color'    => $this->colorPalette->colorFor($group->getId()->toRfc4122()),
                ];
            }, $layout['segments']);

            $weeks[] = ['days' => $days, 'segments' => $segments, 'maxLane' => $layout['maxLane']];
            $cursor  = $cursor->modify('+7 days');
        }

        return $weeks;
    }

    public function isToday(\DateTimeImmutable $day): bool
    {
        return $day->format('Y-m-d') === (new \DateTimeImmutable())->format('Y-m-d');
    }

    public function isCurrentMonth(\DateTimeImmutable $day): bool
    {
        return (int) $day->format('n') === $this->month
            && (int) $day->format('Y') === $this->year;
    }

    /**
     * @return list<Sanction>
     */
    private function getSanctionsForYear(AcademicYear $academicYear): array
    {
        if ($this->sanctionsCache === null) {
            $this->sanctionsCache = $this->sanctionRepository->findWithDatesForAcademicYear($academicYear);
        }

        return $this->sanctionsCache;
    }
}
