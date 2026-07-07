<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\IncidentReportRepository;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class IncidentReportListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

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

    public function __construct(
        private readonly IncidentReportRepository $reports,
        private readonly AppSettings $appSettings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }
        $this->centre = $centre;
    }

    /** @return Group[] */
    public function getGroups(): array
    {
        $user = $this->getUser();
        $year = $this->tenantContext->getViewYear($this->centre);
        if (!$user instanceof Teacher || $year === null) {
            return [];
        }

        return $this->reports->findGroupsWithReports($this->centre, $user, $year);
    }

    /** @return Paginator<IncidentReport> */
    public function getPagination(): Paginator
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return Paginator::fromArray([], 0, max(1, $this->page), $this->appSettings->getInt('page.size'));
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

        return $this->paginate($this->reports->createFilteredQuery(
            $this->centre,
            $user,
            $year,
            $filters,
        ));
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
    public function toggleOwnOnly(): void
    {
        $this->ownOnly = !$this->ownOnly;
        $this->page    = 1;
    }
}
