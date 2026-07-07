<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Communication;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\CommunicationRepository;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class CommunicationHistoryListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $type = '';

    #[LiveProp(writable: true)]
    public string $result = '';

    public function __construct(
        private readonly CommunicationRepository $communications,
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

    /** @return Paginator<Communication> */
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

        return $this->paginate($this->communications->createFilteredQuery($year, $user, [
            'search' => $this->search,
            'type'   => $this->type,
            'result' => $this->result,
        ]));
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->type !== '' || $this->result !== '';
    }

    public function updatedType(): void
    {
        $this->page = 1;
    }

    public function updatedResult(): void
    {
        $this->page = 1;
    }
}
