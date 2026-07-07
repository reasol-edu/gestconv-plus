<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\IncidentReportRepository;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Students with pending-to-notify reports the viewer is authorised to notify, sorted by
 * count descending. Paginated in-memory since the underlying query is a GROUP BY over a
 * dataset expected to stay small (distinct students per centre with pending reports).
 */
#[AsLiveComponent]
class PendingReportsByStudentComponent extends AbstractController
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

    /** @return Paginator<array{student: Student, count: int}> */
    public function getPagination(): Paginator
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $notifierSetting = $this->appSettings->getForCentre('notifications.report_notifier', $this->centre);
        $summary         = $this->reports->findNotifiableSummaryByStudent(
            $this->centre,
            $user,
            is_string($notifierSetting) ? $notifierSetting : 'both',
            $this->tenantContext->getViewYear($this->centre),
        );

        $pageSize = $this->appSettings->getInt('page.size');
        $page     = max(1, $this->page);
        $offset   = ($page - 1) * $pageSize;

        return Paginator::fromArray(
            array_slice($summary, $offset, $pageSize),
            count($summary),
            $page,
            $pageSize,
        );
    }
}
