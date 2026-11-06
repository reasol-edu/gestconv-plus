<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\DurationFormatter;
use App\Service\ExitGroupRow;
use App\Service\ExitReasonStatistics;
use App\Service\ExitStatisticsService;
use App\Service\GroupStatisticsRow;
use App\Service\GroupStatisticsService;
use App\Service\IssueStatisticsService;
use App\Service\IssueStatsRow;
use App\Service\PdfHeader;
use App\Service\PdfHeaderBuilder;
use App\Service\PdfRenderer;
use App\Service\TenantContext;
use App\Service\XlsxExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/informes')]
class ReportsController extends AbstractController
{
    use ReportDateRangeTrait;

    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TenantContext $tenantContext,
        private readonly GroupStatisticsService $groupStatistics,
        private readonly ExitStatisticsService $exitStatistics,
        private readonly IssueStatisticsService $issueStatistics,
        private readonly PdfRenderer $pdfRenderer,
        private readonly PdfHeaderBuilder $pdfHeaderBuilder,
        private readonly XlsxExporter $xlsxExporter,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_reports_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('reports/index.html.twig', [
            'centre' => $centre,
        ]);
    }

    #[Route('/estadisticas-grupo', name: 'app_reports_group_stats', methods: ['GET'])]
    public function groupStats(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);

        [$from, $to] = $this->parseRange($request);

        $report = ($year !== null && $from !== null && $to !== null)
            ? $this->groupStatistics->build($centre, $year, $from, $to)
            : null;

        return $this->render('reports/group_stats.html.twig', [
            'centre' => $centre,
            'year'   => $year,
            'from'   => $from,
            'to'     => $to,
            'report' => $report,
        ]);
    }

    #[Route('/estadisticas-grupo/pdf', name: 'app_reports_group_stats_pdf', methods: ['GET'])]
    public function groupStatsPdf(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        [$from, $to] = $this->parseRange($request);

        if ($year === null || $from === null || $to === null) {
            throw $this->createNotFoundException();
        }

        $report = $this->groupStatistics->build($centre, $year, $from, $to);
        $title  = $this->translator->trans('pdf.group_stats.title', [], 'admin');

        $header = $this->pdfHeaderBuilder->build('group_stats', $centre, [
            'title'         => $title,
            'centre_name'   => $centre->getName(),
            'academic_year' => $year->getName(),
            'date_from'     => $from->format('d/m/Y'),
            'date_to'       => $to->format('d/m/Y'),
        ]);

        return $this->pdfRenderer->render(
            'pdf/group_stats.html.twig',
            [
                'centre' => $centre,
                'year'   => $year,
                'from'   => $from,
                'to'     => $to,
                'report' => $report,
            ],
            $title,
            sprintf('estadisticas-grupo-%s-%s.pdf', $from->format('Y-m-d'), $to->format('Y-m-d')),
            header: $header,
            centre: $centre,
            reportType: 'group_stats',
        );
    }

    #[Route('/estadisticas-grupo/excel', name: 'app_reports_group_stats_xlsx', methods: ['GET'])]
    public function groupStatsXlsx(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        [$from, $to] = $this->parseRange($request);

        if ($year === null || $from === null || $to === null) {
            throw $this->createNotFoundException();
        }

        $report = $this->groupStatistics->build($centre, $year, $from, $to);

        $headers = [
            $this->translator->trans('group_stats.column.course', [], 'admin'),
            $this->translator->trans('group_stats.column.group', [], 'admin'),
            $this->translator->trans('group_stats.column.unique_students', [], 'admin'),
            $this->translator->trans('group_stats.column.reports_normal', [], 'admin'),
            $this->translator->trans('group_stats.column.reports_serious', [], 'admin'),
            $this->translator->trans('group_stats.column.notified_normal', [], 'admin'),
            $this->translator->trans('group_stats.column.notified_serious', [], 'admin'),
            $this->translator->trans('group_stats.column.sanctioned_normal', [], 'admin'),
            $this->translator->trans('group_stats.column.sanctioned_serious', [], 'admin'),
            $this->translator->trans('group_stats.column.prescribed_normal', [], 'admin'),
            $this->translator->trans('group_stats.column.prescribed_serious', [], 'admin'),
            $this->translator->trans('group_stats.column.sanctions', [], 'admin'),
            $this->translator->trans('group_stats.column.sanctions_prescribed', [], 'admin'),
        ];

        $rows = [];
        foreach ($report->courses as $course) {
            foreach ($course->rows as $row) {
                $rows[] = $this->rowToArray($course->course->getName(), $row->group?->getName() ?? '', $row);
            }
            $rows[] = $this->rowToArray(
                $course->course->getName(),
                $this->translator->trans('group_stats.subtotal', [], 'admin'),
                $course->total,
            );
        }
        $rows[] = $this->rowToArray(
            $this->translator->trans('group_stats.grand_total', [], 'admin'),
            '',
            $report->grandTotal,
        );

        return $this->xlsxExporter->createResponse(
            sprintf('estadisticas-grupo-%s-%s.xlsx', $from->format('Y-m-d'), $to->format('Y-m-d')),
            $headers,
            $rows,
        );
    }

    // ── Informe de salidas ────────────────────────────────────────────────────────────────────────

    #[Route('/salidas', name: 'app_reports_exit_stats', methods: ['GET'])]
    public function exitStats(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);

        [$from, $to] = $this->parseRange($request);

        $report = ($year !== null && $from !== null && $to !== null)
            ? $this->exitStatistics->build($centre, $year, $from, $to)
            : null;

        return $this->render('reports/exit_stats.html.twig', [
            'centre' => $centre,
            'year'   => $year,
            'from'   => $from,
            'to'     => $to,
            'report' => $report,
        ]);
    }

    #[Route('/salidas/pdf', name: 'app_reports_exit_stats_pdf', methods: ['GET'])]
    public function exitStatsPdf(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        [$from, $to] = $this->parseRange($request);

        if ($year === null || $from === null || $to === null) {
            throw $this->createNotFoundException();
        }

        $report = $this->exitStatistics->build($centre, $year, $from, $to);
        $title  = $this->translator->trans('pdf.exit_stats.title', [], 'admin');

        return $this->pdfRenderer->render(
            'pdf/exit_stats.html.twig',
            ['centre' => $centre, 'year' => $year, 'from' => $from, 'to' => $to, 'report' => $report],
            $title,
            sprintf('salidas-%s-%s.pdf', $from->format('Y-m-d'), $to->format('Y-m-d')),
            header: $this->rangeHeader('exit_stats', $title, $centre, $year, $from, $to),
            orientation: 'L',
            centre: $centre,
            reportType: 'exit_stats',
        );
    }

    #[Route('/salidas/excel', name: 'app_reports_exit_stats_xlsx', methods: ['GET'])]
    public function exitStatsXlsx(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        [$from, $to] = $this->parseRange($request);

        if ($year === null || $from === null || $to === null) {
            throw $this->createNotFoundException();
        }

        $report = $this->exitStatistics->build($centre, $year, $from, $to);
        $t      = fn (string $key): string => $this->translator->trans($key, [], 'admin');

        $headers = [$t('exit_stats.column.course'), $t('exit_stats.column.group'), $t('exit_stats.column.students'), $t('exit_stats.column.exits')];
        foreach ($report->reasons as $reason) {
            $headers[] = $reason->reason->getName();
        }
        $headers[] = $t('exit_stats.column.not_returned');
        $headers[] = $t('exit_stats.column.average_minutes');
        $headers[] = $t('exit_stats.column.max_minutes');

        $line = function (string $course, string $group, ExitGroupRow $row) use ($report): array {
            $cells = [$course, $group, $row->totals->students, $row->totals->exits];
            foreach ($report->reasons as $reason) {
                $cells[] = $row->byReason[$reason->reason->getId()->toRfc4122()] ?? 0;
            }
            $cells[] = $row->totals->notReturned();
            $cells[] = DurationFormatter::minutes($row->totals->averageSeconds());
            $cells[] = DurationFormatter::minutes($row->totals->returned > 0 ? $row->totals->secondsMax : null);

            return $cells;
        };

        $rows = [];
        foreach ($report->courses as $course) {
            foreach ($course->rows as $row) {
                $rows[] = $line($course->course->getName(), $row->group?->getName() ?? '', $row);
            }
            $rows[] = $line($course->course->getName(), $t('group_stats.subtotal'), $course->total);
        }
        $all = new ExitGroupRow(null, $report->totals, array_combine(
            array_map(static fn (ExitReasonStatistics $r): string => $r->reason->getId()->toRfc4122(), $report->reasons),
            array_map(static fn (ExitReasonStatistics $r): int => $r->totals->exits, $report->reasons),
        ));
        $rows[] = $line($t('group_stats.grand_total'), '', $all);

        return $this->xlsxExporter->createResponse(
            sprintf('salidas-%s-%s.xlsx', $from->format('Y-m-d'), $to->format('Y-m-d')),
            $headers,
            $rows,
        );
    }

    // ── Informe de incidencias ────────────────────────────────────────────────────────────────────

    #[Route('/incidencias', name: 'app_reports_issue_stats', methods: ['GET'])]
    public function issueStats(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);

        [$from, $to] = $this->parseRange($request);

        $report = ($year !== null && $from !== null && $to !== null)
            ? $this->issueStatistics->build($centre, $year, $from, $to)
            : null;

        return $this->render('reports/issue_stats.html.twig', [
            'centre' => $centre,
            'year'   => $year,
            'from'   => $from,
            'to'     => $to,
            'report' => $report,
        ]);
    }

    #[Route('/incidencias/pdf', name: 'app_reports_issue_stats_pdf', methods: ['GET'])]
    public function issueStatsPdf(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        [$from, $to] = $this->parseRange($request);

        if ($year === null || $from === null || $to === null) {
            throw $this->createNotFoundException();
        }

        $report = $this->issueStatistics->build($centre, $year, $from, $to);
        $title  = $this->translator->trans('pdf.issue_stats.title', [], 'admin');

        return $this->pdfRenderer->render(
            'pdf/issue_stats.html.twig',
            ['centre' => $centre, 'year' => $year, 'from' => $from, 'to' => $to, 'report' => $report],
            $title,
            sprintf('incidencias-%s-%s.pdf', $from->format('Y-m-d'), $to->format('Y-m-d')),
            header: $this->rangeHeader('issue_stats', $title, $centre, $year, $from, $to),
            centre: $centre,
            reportType: 'issue_stats',
        );
    }

    #[Route('/incidencias/excel', name: 'app_reports_issue_stats_xlsx', methods: ['GET'])]
    public function issueStatsXlsx(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        [$from, $to] = $this->parseRange($request);

        if ($year === null || $from === null || $to === null) {
            throw $this->createNotFoundException();
        }

        $report = $this->issueStatistics->build($centre, $year, $from, $to);
        $t      = fn (string $key): string => $this->translator->trans($key, [], 'admin');

        $headers = [
            $t('issue_stats.column.root_type'),
            $t('issue_stats.column.type'),
            $t('issue_stats.column.registered'),
            $t('issue_stats.column.open'),
            $t('issue_stats.column.solved'),
            $t('issue_stats.column.not_applicable'),
            $t('issue_stats.column.duplicate'),
            $t('issue_stats.column.overdue'),
            $t('issue_stats.column.average_close_days'),
            $t('issue_stats.column.median_close_days'),
            $t('issue_stats.column.first_management_days'),
            $t('issue_stats.column.on_time_max_percent'),
            $t('issue_stats.column.on_time_recommended_percent'),
        ];

        $line = static fn (string $root, string $label, IssueStatsRow $r): array => [
            $root,
            $label,
            $r->registered,
            $r->open,
            $r->solved,
            $r->notApplicable,
            $r->duplicate,
            $r->overdue,
            DurationFormatter::days($r->avgCloseSeconds),
            DurationFormatter::days($r->medianCloseSeconds),
            DurationFormatter::days($r->avgFirstManagementSeconds),
            $r->onTimeMaxPercent(),
            $r->onTimeRecommendedPercent(),
        ];

        $rows = [];
        foreach ($report->branches as $branch) {
            foreach ($branch->rows as $row) {
                $rows[] = $line($branch->rootName, $row->label, $row);
            }
            $rows[] = $line($branch->rootName, $t('group_stats.subtotal'), $branch->total);
        }
        $rows[] = $line($t('group_stats.grand_total'), '', $report->totals);

        return $this->xlsxExporter->createResponse(
            sprintf('incidencias-%s-%s.xlsx', $from->format('Y-m-d'), $to->format('Y-m-d')),
            $headers,
            $rows,
        );
    }

    /** @param 'exit_stats'|'issue_stats' $type */
    private function rangeHeader(string $type,string $title, EducationalCentre $centre, AcademicYear $year, \DateTimeImmutable $from, \DateTimeImmutable $to): PdfHeader
    {
        return $this->pdfHeaderBuilder->build($type, $centre, [
            'title'         => $title,
            'centre_name'   => $centre->getName(),
            'academic_year' => $year->getName(),
            'date_from'     => $from->format('d/m/Y'),
            'date_to'       => $to->format('d/m/Y'),
        ]);
    }

    /** @return list<string|int> */
    private function rowToArray(string $courseName, string $groupName, GroupStatisticsRow $row): array
    {
        return [
            $courseName,
            $groupName,
            $row->uniqueStudents,
            $row->reportsNormal,
            $row->reportsSerious,
            $row->notifiedNormal,
            $row->notifiedSerious,
            $row->sanctionedNormal,
            $row->sanctionedSerious,
            $row->prescribedNormal,
            $row->prescribedSerious,
            $row->sanctionsCount,
            $row->sanctionsPrescribed,
        ];
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findById($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }
}
