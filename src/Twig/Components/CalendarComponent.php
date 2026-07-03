<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Sanction;
use App\Repository\SanctionRepository;
use App\Service\CalendarMonthGridBuilder;
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
        private readonly CalendarMonthGridBuilder $gridBuilder,
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

        $sanctions = $this->getSanctionsForYear($academicYear);

        return $this->gridBuilder->build($this->year, $this->month, $sanctions);
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
