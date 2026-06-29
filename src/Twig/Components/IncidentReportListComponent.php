<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\IncidentReportRepository;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class IncidentReportListComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public bool $ownOnly = false;

    #[LiveProp(writable: true)]
    public string $groupId = '';

    #[LiveProp(writable: true)]
    public string $dateFrom = '';

    #[LiveProp(writable: true)]
    public string $dateTo = '';

    /** '' = all, '1' = only serious, '0' = only non-serious */
    #[LiveProp(writable: true)]
    public string $serious = '';

    /** '' = all, '1' = expelled, '0' = not expelled */
    #[LiveProp(writable: true)]
    public string $expelled = '';

    #[LiveProp(writable: true)]
    public int $page = 1;

    public function __construct(
        private readonly IncidentReportRepository $reports,
        private readonly AppSettings $appSettings,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }
        $this->centre = $centre;
    }

    /** @return Paginator<IncidentReport> */
    public function getPagination(): Paginator
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $filters = [
            'search'   => $this->search,
            'ownOnly'  => $this->ownOnly,
            'groupId'  => $this->groupId,
            'dateFrom' => $this->dateFrom,
            'dateTo'   => $this->dateTo,
        ];

        if ($this->serious !== '') {
            $filters['serious'] = $this->serious === '1';
        }

        if ($this->expelled !== '') {
            $filters['expelled'] = $this->expelled === '1';
        }

        return new Paginator(
            $this->reports->createFilteredQuery($this->centre, $user, $filters),
            max(1, $this->page),
            $this->appSettings->getInt('page.size'),
        );
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->ownOnly
            || $this->groupId !== ''
            || $this->dateFrom !== ''
            || $this->dateTo !== ''
            || $this->serious !== ''
            || $this->expelled !== '';
    }

    #[LiveAction]
    public function resetPage(): void
    {
        $this->page = 1;
    }

    #[LiveAction]
    public function setPage(#[LiveArg] int $page): void
    {
        $this->page = max(1, $page);
    }

    #[LiveAction]
    public function toggleOwnOnly(): void
    {
        $this->ownOnly = !$this->ownOnly;
        $this->page    = 1;
    }
}
