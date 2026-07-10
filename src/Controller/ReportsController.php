<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\GroupStatisticsRow;
use App\Service\GroupStatisticsService;
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
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TenantContext $tenantContext,
        private readonly GroupStatisticsService $groupStatistics,
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
            $this->translator->trans('group_stats.column.programme', [], 'admin'),
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
        ];

        $rows = [];
        foreach ($report->programmes as $programme) {
            foreach ($programme->rows as $row) {
                $rows[] = $this->rowToArray($programme->programme->getName(), $row->group?->getName() ?? '', $row);
            }
            $rows[] = $this->rowToArray(
                $programme->programme->getName(),
                $this->translator->trans('group_stats.subtotal', [], 'admin'),
                $programme->total,
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

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function parseRange(Request $request): array
    {
        $from = null;
        $to   = null;

        $fromRaw = trim($request->query->getString('from'));
        if ($fromRaw !== '') {
            try {
                $from = new \DateTimeImmutable($fromRaw);
            } catch (\Exception) {
                // invalid date, ignore
            }
        }

        $toRaw = trim($request->query->getString('to'));
        if ($toRaw !== '') {
            try {
                $to = new \DateTimeImmutable($toRaw);
            } catch (\Exception) {
                // invalid date, ignore
            }
        }

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /** @return list<string|int> */
    private function rowToArray(string $programmeName, string $groupName, GroupStatisticsRow $row): array
    {
        return [
            $programmeName,
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
