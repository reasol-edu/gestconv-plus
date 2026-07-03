<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\SanctionRepository;
use App\Service\AppSettings;
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

    public function __construct(
        private readonly SanctionRepository $sanctions,
        private readonly AppSettings $appSettings,
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
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $this->paginate($this->sanctions->createFilteredQuery($this->centre, $user, [
            'search'         => $this->search,
            'effectiveToday' => $this->effectiveToday,
            'pendingOnly'    => $this->pendingOnly,
        ]));
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->effectiveToday || $this->pendingOnly;
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
}
