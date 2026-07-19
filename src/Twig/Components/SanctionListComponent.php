<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\SanctionRepository;
use App\Repository\SanctionTaskRepository;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class SanctionListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public bool $effectiveToday = false;

    #[LiveProp(writable: true)]
    public bool $pendingOnly = false;

    #[LiveProp(writable: true)]
    public bool $pendingTasksOnly = false;

    /** @var Paginator<Sanction>|null */
    private ?Paginator $paginationCache = null;

    public function __construct(
        private readonly SanctionRepository $sanctions,
        private readonly SanctionTaskRepository $sanctionTasks,
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

    /** @return Paginator<Sanction> */
    public function getPagination(): Paginator
    {
        if ($this->paginationCache !== null) {
            return $this->paginationCache;
        }

        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return $this->paginationCache = Paginator::fromArray([], 0, max(1, $this->page), $this->appSettings->getInt('page.size'));
        }

        return $this->paginationCache = $this->paginate($this->sanctions->createFilteredQuery($this->centre, $user, $year, [
            'search'           => $this->search,
            'effectiveToday'   => $this->effectiveToday,
            'pendingOnly'      => $this->pendingOnly,
            'pendingTasksOnly' => $this->pendingTasksOnly,
        ]));
    }

    /**
     * Task completion counts (completed/total) for the sanctions on the current page,
     * keyed by sanction UUID. Sanctions with no tasks are absent from the map.
     *
     * @return array<string, array{completed: int, total: int}>
     */
    public function getTaskCompletionCounts(): array
    {
        /** @var list<Sanction> $sanctions */
        $sanctions = $this->getPagination()->getItems();

        return $this->sanctionTasks->findCompletionCountsBySanctions($sanctions);
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->effectiveToday || $this->pendingOnly || $this->pendingTasksOnly;
    }

    #[LiveAction]
    public function toggleEffectiveToday(): void
    {
        $this->effectiveToday = !$this->effectiveToday;
        $this->page           = 1;
    }

    #[LiveAction]
    public function togglePendingOnly(): void
    {
        $this->pendingOnly = !$this->pendingOnly;
        $this->page        = 1;
    }

    #[LiveAction]
    public function togglePendingTasksOnly(): void
    {
        $this->pendingTasksOnly = !$this->pendingTasksOnly;
        $this->page             = 1;
    }
}
