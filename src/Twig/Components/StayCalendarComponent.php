<?php

namespace App\Twig\Components;

use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class StayCalendarComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public int $year = 0;

    #[LiveProp(writable: true)]
    public int $month = 0;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
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

    public function getMonthLabel(): string
    {
        return $this->translator->trans('month.' . $this->month, [], 'calendar') . ' ' . $this->year;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getWeeks(): array
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null || $this->tenantContext->getViewYear($centre) === null) {
            return [];
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
                $days[] = $d;
                $d      = $d->modify('+1 day');
            }
            $weeks[] = ['days' => $days, 'segments' => [], 'maxLane' => -1];
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
}
