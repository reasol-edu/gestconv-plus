<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AcademicYear;
use App\Entity\Communication;
use App\Entity\DailyNote;
use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Repository\CommunicationRepository;
use App\Repository\DailyNoteRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use App\Repository\SanctionTaskRepository;
use App\Repository\StudentRepository;
use App\Service\ActivityLogService;
use App\Service\AppSettingsInterface;
use App\Service\Feature;
use App\Service\FeatureToggle;
use App\Service\TeacherYearRoles;
use App\Service\TenantContext;
use App\Service\XlsxExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Exportación a hoja de cálculo de los listados (partes, sanciones, notas, historial de notificaciones y Mi
 * tutoría) con los filtros que tiene puestos la pantalla. Cada exportación vuelve a ejecutar la MISMA consulta
 * por visor que el listado: devuelve todas las páginas, nunca más filas de las que esa persona ve y solo las
 * columnas que el listado enseña (sin datos de contacto de las familias). Cada una queda en el registro de
 * actividad.
 */
#[Route('/exportar')]
class ListExportController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly XlsxExporter $xlsx,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
        private readonly ClockInterface $clock,
        private readonly FeatureToggle $features,
        private readonly AppSettingsInterface $settings,
        private readonly IncidentReportRepository $reports,
        private readonly SanctionRepository $sanctions,
        private readonly SanctionTaskRepository $sanctionTasks,
        private readonly DailyNoteRepository $notes,
        private readonly CommunicationRepository $communications,
        private readonly StudentRepository $students,
        private readonly TeacherYearRoles $roles,
    ) {}

    #[Route('/partes', name: 'app_export_incidents', methods: ['GET'])]
    public function incidents(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$user, $year] = $this->context($centre);

        $filters = [
            'search'   => $request->query->getString('search'),
            'ownOnly'  => $request->query->getBoolean('ownOnly'),
            'groupId'  => $request->query->getString('groupId'),
            'dateFrom' => $request->query->getString('dateFrom'),
            'dateTo'   => $request->query->getString('dateTo'),
        ];
        foreach (['serious', 'expelled'] as $flag) {
            if ($request->query->getString($flag) !== '') {
                $filters[$flag] = $request->query->getString($flag) === '1';
            }
        }

        /** @var list<IncidentReport> $found */
        $found = $this->reports->createFilteredQuery($centre, $user, $year, $filters)->getResult();

        $rows = [];
        foreach ($found as $report) {
            $behaviors = [];
            foreach ($report->getBehaviors() as $behavior) {
                $behaviors[] = $behavior->getName();
            }
            $rows[] = [
                $report->getNumber(),
                $report->getOccurredAt()->format($this->t('format.datetime', 'messages')),
                $report->getStudent()->getName()->getLastName() . ', ' . $report->getStudent()->getName()->getFirstName(),
                $report->getGroup()->getName(),
                $report->getRegisteredBy()->getName()->getLastName() . ', ' . $report->getRegisteredBy()->getName()->getFirstName(),
                implode('; ', $behaviors),
                trim(html_entity_decode(strip_tags($report->getDescription()), ENT_QUOTES | ENT_HTML5)),
                $this->yesNo($report->isExpelledFromClass()),
                $this->t($report->isNotified() ? 'notification.badge.notified' : 'notification.badge.pending', 'notifications'),
                $report->getPrescribedAt()?->format($this->t('format.date', 'messages')) ?? '',
            ];
        }

        return $this->respond('incidents', 'partes', [
            '#',
            $this->t('incident.col.occurred_at'),
            $this->t('incident.col.student'),
            $this->t('incident.col.group'),
            $this->t('incident.col.teacher'),
            $this->t('incident.col.behaviors'),
            $this->t('incident.col.description'),
            $this->t('incident.col.expelled'),
            $this->t('incident.col.status'),
            $this->t('incident.badge.prescribed'),
        ], $rows, count($rows));
    }

    #[Route('/sanciones', name: 'app_export_sanctions', methods: ['GET'])]
    public function sanctions(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$user, $year] = $this->context($centre);

        /** @var list<Sanction> $found */
        $found = $this->sanctions->createFilteredQuery($centre, $user, $year, [
            'search'            => $request->query->getString('search'),
            'effectiveToday'    => $request->query->getBoolean('effectiveToday'),
            'pendingOnly'       => $request->query->getBoolean('pendingOnly'),
            'pendingTasksOnly'  => $request->query->getBoolean('pendingTasksOnly'),
            'pendingSenecaOnly' => $request->query->getBoolean('pendingSenecaOnly'),
        ])->getResult();

        $taskCounts = $this->sanctionTasks->findCompletionCountsBySanctions($found);
        $date       = $this->t('format.date', 'messages');

        $rows = [];
        foreach ($found as $sanction) {
            $measures = [];
            foreach ($sanction->getMeasures() as $measure) {
                $measures[] = $measure->getName();
            }
            $counts   = $taskCounts[$sanction->getId()->toRfc4122()] ?? null;
            $from     = $sanction->getEffectiveFrom();
            $to       = $sanction->getEffectiveTo();
            $rows[]   = [
                $sanction->getStudent()->getName()->getLastName() . ', ' . $sanction->getStudent()->getName()->getFirstName(),
                $sanction->getGroup()->getName(),
                $sanction->isNoMeasureApplied() ? $this->t('sanction.badge.no_measure') : implode('; ', $measures),
                $from !== null && $to !== null ? $from->format($date) . ' – ' . $to->format($date) : '',
                $counts !== null ? $counts['completed'] . '/' . $counts['total'] : '',
                $this->t($sanction->isNotified() ? 'notification.badge.notified' : 'notification.badge.pending', 'notifications'),
                $sanction->getCreatedAt()->format($date),
            ];
        }

        return $this->respond('sanctions', 'sanciones', [
            $this->t('sanction.col.student'),
            $this->t('incident.col.group'),
            $this->t('sanction.col.measures'),
            $this->t('sanction.col.effective_period'),
            $this->t('sanction.col.tasks'),
            $this->t('sanction.col.notified'),
            $this->t('sanction.col.created_at'),
        ], $rows, count($rows));
    }

    #[Route('/notas', name: 'app_export_notes', methods: ['GET'])]
    public function notes(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        if (!$this->features->isEnabledForCentre(Feature::DailyNotes, $centre)) {
            throw $this->createNotFoundException();
        }
        [$user, $year] = $this->context($centre);

        /** @var list<DailyNote> $found */
        $found = $this->notes->createFilteredQuery($centre, $user, $year, [
            'search'       => $request->query->getString('search'),
            'ownOnly'      => $request->query->getBoolean('ownOnly'),
            'tutoringOnly' => $request->query->getBoolean('tutoringOnly'),
            'groupId'      => $request->query->getString('groupId'),
            'typeId'       => $request->query->getString('typeId'),
        ])->getResult();

        $rows = [];
        foreach ($found as $note) {
            $rows[] = [
                $note->getOccurredAt()->format($this->t('format.datetime', 'messages')),
                $note->getStudent()->getName()->getLastName() . ', ' . $note->getStudent()->getName()->getFirstName(),
                $note->getGroup()->getName(),
                $note->getType()->getName(),
                $note->getRegisteredBy()->getName()->getLastName() . ', ' . $note->getRegisteredBy()->getName()->getFirstName(),
                (string) $note->getObservations(),
            ];
        }

        return $this->respond('notes', 'notas', [
            $this->t('daily_note.col.occurred_at'),
            $this->t('daily_note.col.student'),
            $this->t('daily_note.col.group'),
            $this->t('daily_note.col.type'),
            $this->t('daily_note.col.teacher'),
            $this->t('daily_note.col.observations'),
        ], $rows, count($rows));
    }

    #[Route('/notificaciones', name: 'app_export_communications', methods: ['GET'])]
    public function communications(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$user, $year] = $this->context($centre);

        /** @var list<Communication> $found */
        $found = $this->communications->createFilteredQuery($year, $user, [
            'search' => $request->query->getString('search'),
            'type'   => $request->query->getString('type'),
            'result' => $request->query->getString('result'),
        ])->getResult();

        $rows = [];
        foreach ($found as $communication) {
            $report  = $communication->getIncidentReport();
            $target  = $report ?? $communication->getSanction();
            if ($target === null) {
                continue;
            }
            $rows[] = [
                $communication->getPerformedAt()->format($this->t('format.datetime', 'messages')),
                $target->getStudent()->getName()->getLastName() . ', ' . $target->getStudent()->getName()->getFirstName(),
                $target->getGroup()->getName(),
                $this->t($report !== null ? 'notification.history_list.type_report_badge' : 'notification.history_list.type_sanction_badge', 'notifications'),
                $communication->getMethod()->getName(),
                $communication->getPerformedBy()->getName()->getLastName() . ', ' . $communication->getPerformedBy()->getName()->getFirstName(),
                $this->t('notification.result.' . $communication->getResult()->value, 'notifications'),
                (string) $communication->getDescription(),
            ];
        }

        return $this->respond('communications', 'notificaciones', [
            $this->t('notification.history_list.col.date', 'notifications'),
            $this->t('notification.history_list.col.student', 'notifications'),
            $this->t('notification.history_list.col.group', 'notifications'),
            $this->t('notification.history_list.col.type', 'notifications'),
            $this->t('notification.history_list.col.method', 'notifications'),
            $this->t('notification.history_list.col.performed_by', 'notifications'),
            $this->t('notification.history_list.col.result', 'notifications'),
            $this->t('notification.history_list.col.description', 'notifications'),
        ], $rows, count($rows));
    }

    #[Route('/tutoria', name: 'app_export_tutorship', methods: ['GET'])]
    public function tutorship(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$user, $year] = $this->context($centre);
        // Como la pantalla Mi tutoría: solo quien tutoriza algún grupo.
        if (!$this->roles->tutorsAnyGroup($user, $year)) {
            throw $this->createAccessDeniedException();
        }

        $days              = $this->settings->getForCentre('notifications.sanction_auto_prescribe_days', $centre);
        $withSanctionsLate = is_int($days) && $days > 0;

        $summary = $this->students->findTutoredSummary($user, $year, [
            'search'  => trim($request->query->getString('search')),
            'groupId' => trim($request->query->getString('groupId')),
            'sort'    => $request->query->getString('sort'),
            'sortDir' => $request->query->getString('sortDir', 'asc'),
        ]);

        $rows = [];
        foreach ($summary as $row) {
            $line = [
                $row['lastName'] . ', ' . $row['firstName'],
                $row['groupName'],
                $row['reportsTotal'],
                $row['reportsTotal'] - $row['reportsSerious'],
                $row['reportsSerious'],
                $row['reportsUnnotified'],
                $row['reportsPrescribed'],
                $row['sanctionsTotal'],
                $row['sanctionsUnnotified'],
            ];
            if ($withSanctionsLate) {
                $line[] = $row['sanctionsPrescribed'];
            }
            $rows[] = $line;
        }

        $headers = [
            $this->t('tutorship.col.name'),
            $this->t('tutorship.col.group'),
            $this->t('tutorship.col.reports_total'),
            $this->t('tutorship.col.reports_normal'),
            $this->t('tutorship.col.reports_serious'),
            $this->t('tutorship.col.reports_unnotified'),
            $this->t('tutorship.col.reports_prescribed'),
            $this->t('tutorship.col.sanctions_total'),
            $this->t('tutorship.col.sanctions_unnotified'),
        ];
        if ($withSanctionsLate) {
            $headers[] = $this->t('tutorship.col.sanctions_prescribed');
        }

        return $this->respond('tutorship', 'mi-tutoria', $headers, $rows, count($rows));
    }

    /** @return array{0: Teacher, 1: AcademicYear} */
    private function context(EducationalCentre $centre): array
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }
        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        return [$user, $year];
    }

    /**
     * @param list<string>                                           $headers
     * @param list<list<string|int|float|null>>                      $rows
     */
    private function respond(string $list, string $slug, array $headers, array $rows, int $count): Response
    {
        $this->activityLog->log('list.exported', ['list' => $list, 'rows' => $count]);

        return $this->xlsx->createResponse(
            sprintf('%s-%s.xlsx', $slug, $this->clock->now()->format('Y-m-d')),
            $headers,
            $rows,
        );
    }

    private function yesNo(bool $value): string
    {
        return $this->t($value ? 'ui.yes' : 'ui.no', 'messages');
    }

    private function t(string $key, string $domain = 'admin'): string
    {
        return $this->translator->trans($key, [], $domain);
    }
}
