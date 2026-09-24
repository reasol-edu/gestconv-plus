<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use App\Service\AppSettings;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Students with pending-to-notify reports and/or sanctions the viewer is authorised to notify,
 * sorted by total pending count descending. Paginated in-memory since the underlying queries are
 * GROUP BY over a dataset expected to stay small (distinct students per centre with something
 * pending).
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

    /** @return Paginator<array{student: Student, reportCount: int, sanctionCount: int}> */
    public function getPagination(): Paginator
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $pageSize = $this->appSettings->getInt('page.size');
        $page     = max(1, $this->page);
        $offset   = ($page - 1) * $pageSize;

        $year = $this->tenantContext->getViewYear($this->centre);
        if ($year === null) {
            return Paginator::fromArray([], 0, $page, $pageSize);
        }

        $reportNotifierSetting = $this->appSettings->getForCentre('notifications.report_notifier', $this->centre);
        $reportSummary         = $this->reports->findNotifiableSummaryByStudent(
            $this->centre,
            $user,
            is_string($reportNotifierSetting) ? $reportNotifierSetting : 'both',
            $year,
        );

        $sanctionNotifierSetting = $this->appSettings->getForCentre('notifications.sanction_notifier', $this->centre);
        $sanctionSummary         = $this->sanctions->findNotifiableSummaryByStudent(
            $this->centre,
            $user,
            is_string($sanctionNotifierSetting) ? $sanctionNotifierSetting : 'both',
            $year,
        );

        $summary = $this->mergeSummaries($reportSummary, $sanctionSummary);

        return Paginator::fromArray(
            array_slice($summary, $offset, $pageSize),
            count($summary),
            $page,
            $pageSize,
        );
    }

    /**
     * Merges the per-entity-type student summaries into a single list, keeping every student
     * that has at least one pending report or sanction, ordered by combined pending count
     * descending then student name ascending.
     *
     * @param list<array{student: Student, count: int}> $reportSummary
     * @param list<array{student: Student, count: int}> $sanctionSummary
     * @return list<array{student: Student, reportCount: int, sanctionCount: int}>
     */
    private function mergeSummaries(array $reportSummary, array $sanctionSummary): array
    {
        /** @var array<string, array{student: Student, reportCount: int, sanctionCount: int}> $byStudentId */
        $byStudentId = [];

        foreach ($reportSummary as $row) {
            $byStudentId[$row['student']->getId()->toRfc4122()] = [
                'student'       => $row['student'],
                'reportCount'   => $row['count'],
                'sanctionCount' => 0,
            ];
        }

        foreach ($sanctionSummary as $row) {
            $id = $row['student']->getId()->toRfc4122();
            if (isset($byStudentId[$id])) {
                $byStudentId[$id]['sanctionCount'] = $row['count'];
            } else {
                $byStudentId[$id] = [
                    'student'       => $row['student'],
                    'reportCount'   => 0,
                    'sanctionCount' => $row['count'],
                ];
            }
        }

        $summary = array_values($byStudentId);

        usort($summary, static function (array $a, array $b): int {
            $totalCompare = ($b['reportCount'] + $b['sanctionCount']) <=> ($a['reportCount'] + $a['sanctionCount']);
            if ($totalCompare !== 0) {
                return $totalCompare;
            }

            $lastNameCompare = $a['student']->getName()->getLastName() <=> $b['student']->getName()->getLastName();

            return $lastNameCompare !== 0
                ? $lastNameCompare
                : $a['student']->getName()->getFirstName() <=> $b['student']->getName()->getFirstName();
        });

        return $summary;
    }
}
