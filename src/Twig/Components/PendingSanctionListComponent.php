<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\SanctionRepository;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class PendingSanctionListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    public function __construct(
        private readonly SanctionRepository $sanctions,
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
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return Paginator::fromArray([], 0, max(1, $this->page), $this->appSettings->getInt('page.size'));
        }

        return $this->paginate($this->sanctions->createPendingQuery($this->centre, $user, $year));
    }
}
