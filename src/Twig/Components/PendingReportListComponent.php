<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\IncidentReportRepository;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class PendingReportListComponent extends AbstractController
{
    use DefaultActionTrait;
    use PaginatedListTrait;

    #[LiveProp]
    public EducationalCentre $centre;

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

        return $this->paginate($this->reports->createPendingQuery($this->centre, $user, $year));
    }
}
