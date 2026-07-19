<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\GroupRepository;
use App\Repository\StudentRepository;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class TutorshipListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp]
    public Teacher $viewer;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $groupId = '';

    #[LiveProp(writable: true)]
    public string $sort = '';

    #[LiveProp(writable: true)]
    public string $sortDir = 'asc';

    public function __construct(
        private readonly StudentRepository $students,
        private readonly GroupRepository $groups,
        private readonly AppSettings $appSettings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function mount(EducationalCentre $centre, Teacher $viewer): void
    {
        $this->centre = $centre;
        $this->viewer = $viewer;
    }

    /** @return Group[] */
    public function getAvailableGroups(): array
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return [];
        }

        return $this->groups->findTutoredByActiveYear($this->centre, $this->viewer, $year);
    }

    /** @return Paginator<array{studentId: string, firstName: string, lastName: string, groupId: string, groupName: string, reportsTotal: int, reportsSerious: int, reportsUnnotified: int, reportsPrescribed: int, sanctionsTotal: int, sanctionsUnnotified: int}> */
    public function getPagination(): Paginator
    {
        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year instanceof AcademicYear) {
            $rows = $this->students->findTutoredSummary($this->viewer, $year, [
                'search'  => trim($this->search),
                'groupId' => trim($this->groupId),
                'sort'    => $this->sort,
                'sortDir' => $this->sortDir,
            ]);
        } else {
            $rows = [];
        }

        $pageSize = $this->appSettings->getInt('page.size');
        $page     = max(1, $this->page);
        $total    = count($rows);
        $offset   = ($page - 1) * $pageSize;

        return Paginator::fromArray(array_slice($rows, $offset, $pageSize), $total, $page, $pageSize);
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->groupId !== '';
    }

    #[LiveAction]
    public function sortBy(#[LiveArg] string $column): void
    {
        if ($this->sort === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->sortDir = 'asc';
        }
        $this->page = 1;
    }
}
