<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Entity\Issue;
use App\Entity\IssueStatus;
use App\Entity\ResourceBooking;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\Teacher;
use App\Repository\DailyNoteRepository;
use App\Repository\IssueRepository;
use App\Repository\ResourceBookingRepository;
use App\Repository\SanctionTaskRepository;
use App\Service\Feature;
use App\Service\FeatureToggle;
use App\Service\IssueAccess;
use App\Service\IssueListFilter;
use App\Service\PendingNotificationQueue;
use App\Service\ResourceBookingAccess;
use App\Service\TenantContext;
use App\Service\UpcomingPrescriptions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Campana de notificaciones. Agrupa lo pendiente en secciones por prioridad: primero lo que
 * prescribe pronto (se pierde para siempre si no se comunica a tiempo), luego partes, sanciones,
 * tareas de sanción y, solo para la administración del centro, estudiantes cuyas notas llegan al
 * umbral para generar un parte. Cada sección muestra unos pocos elementos para que ninguna tape a
 * las demás; el resto está en la pantalla de notificaciones.
 */
#[AsLiveComponent]
class NotificationBellComponent extends AbstractController
{
    use DefaultActionTrait;

    public const MAX_PER_SECTION = 4;

    /** Orden de las secciones, de más a menos urgente. */
    public const SECTIONS = ['prescription', 'report', 'sanction', 'task', 'note_threshold', 'booking_validation', 'booking_resolved', 'issue_overdue', 'issue_assigned', 'issue_attend', 'issue_news'];

    /** @var array<string, list<array{type: string, entity: IncidentReport|Sanction|SanctionTask|ResourceBooking|Issue|array<string, mixed>, date: \DateTimeImmutable, daysLeft: ?int}>>|null */
    private ?array $grouped = null;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PendingNotificationQueue $pendingNotificationQueue,
        private readonly SanctionTaskRepository $sanctionTaskRepository,
        private readonly DailyNoteRepository $dailyNoteRepository,
        private readonly UpcomingPrescriptions $upcomingPrescriptions,
        private readonly FeatureToggle $features,
        private readonly ResourceBookingRepository $resourceBookings,
        private readonly ResourceBookingAccess $bookingAccess,
        private readonly IssueRepository $issues,
        private readonly IssueAccess $issueAccess,
    ) {}

    /**
     * Elementos pendientes por sección (sin truncar), solo las que tienen alguno.
     *
     * @return array<string, list<array{type: string, entity: IncidentReport|Sanction|SanctionTask|ResourceBooking|Issue|array<string, mixed>, date: \DateTimeImmutable, daysLeft: ?int}>>
     */
    private function grouped(): array
    {
        if ($this->grouped !== null) {
            return $this->grouped;
        }

        $centre = $this->tenantContext->getSelectedCentre();
        $user   = $this->getUser();
        if ($centre === null || !$user instanceof Teacher) {
            return $this->grouped = [];
        }

        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            return $this->grouped = [];
        }

        $sections = array_fill_keys(self::SECTIONS, []);

        // El personal no docente solo ve lo de las incidencias (más abajo).
        if (!$user->isNonTeaching()) {
        // Lo próximo a prescribir va arriba, y no se repite en las secciones de pendientes.
        $prescribing = [];
        if (!$this->tenantContext->isViewingNonActiveYear($centre)) {
            foreach ($this->upcomingPrescriptions->forViewer($centre, $user, $year) as $row) {
                $entity = $row['report'] ?? $row['sanction'];
                if ($entity === null) {
                    continue;
                }
                $prescribing[$entity->getId()->toRfc4122()] = true;
                $date                                      = $entity instanceof IncidentReport ? $entity->getOccurredAt() : $entity->getCreatedAt();
                $sections['prescription'][]                = ['type' => 'prescription_' . $row['type'], 'entity' => $entity, 'date' => $date, 'daysLeft' => $row['daysLeft']];
            }
        }

        $queue = $this->pendingNotificationQueue->forViewer($centre, $user, $year);
        foreach ($queue['reports'] as $report) {
            if (!isset($prescribing[$report->getId()->toRfc4122()])) {
                $sections['report'][] = ['type' => 'report', 'entity' => $report, 'date' => $report->getOccurredAt(), 'daysLeft' => null];
            }
        }
        foreach ($queue['sanctions'] as $sanction) {
            if (!isset($prescribing[$sanction->getId()->toRfc4122()])) {
                $sections['sanction'][] = ['type' => 'sanction', 'entity' => $sanction, 'date' => $sanction->getCreatedAt(), 'daysLeft' => null];
            }
        }
        if ($this->features->isEnabled(Feature::SanctionTasks)) {
            foreach ($this->sanctionTaskRepository->findPendingForTeacher($centre, $user, $year) as $task) {
                $sections['task'][] = [
                    'type'     => 'task',
                    'entity'   => $task,
                    'date'     => $task->getSanction()->getEffectiveFrom() ?? $task->getSanction()->getCreatedAt(),
                    'daysLeft' => null,
                ];
            }
        }

        // Las notas que llegan al umbral de parte solo interesan a la administración del centro;
        // al profesorado y a los tutores les basta con su pantalla de notas y el panel de inicio.
        if ($this->features->isEnabled(Feature::DailyNotes) && ($user->isAdmin() || $centre->getAdmins()->contains($user))) {
            foreach ($this->dailyNoteRepository->findStudentsAtThreshold($centre, $user, $year) as $row) {
                $sections['note_threshold'][] = [
                    'type'     => 'note_threshold',
                    'entity'   => $row,
                    'date'     => $row['lastNoteAt'] ?? new \DateTimeImmutable('@0'),
                    'daysLeft' => null,
                ];
            }
        }

        // Reservas: solicitudes que le toca validar y respuestas a las suyas que aún no ha visto.
        if ($this->features->isEnabledForCentre(Feature::Bookings, $centre)) {
            if ($this->bookingAccess->hasManagerRole($user, $centre)) {
                $categories = $this->bookingAccess->isManagement($user, $centre) ? null : $this->bookingAccess->managedCategories($user, $centre);
                foreach ($this->resourceBookings->findPendingForCategories($year, $categories) as $booking) {
                    $sections['booking_validation'][] = ['type' => 'booking_validation', 'entity' => $booking, 'date' => $booking->getDate(), 'daysLeft' => null];
                }
            }
            foreach ($this->resourceBookings->findUnseenResolutionsFor($user, $year) as $booking) {
                $sections['booking_resolved'][] = ['type' => 'booking_' . $booking->getStatus()->value, 'entity' => $booking, 'date' => $booking->getDate(), 'daysLeft' => null];
            }
        }

        }

        // Incidencias: a quien las gestiona, las nuevas por atender y las vencidas; a quien las registró, las novedades recientes.
        if ($this->features->isEnabledForCentre(Feature::Issues, $centre)) {
            $management = $this->issueAccess->isManagement($user, $centre);
            $managedIds = $this->issueAccess->managedTypeIds($user, $centre);
            $now        = \Symfony\Component\Clock\now();

            if ($management || $managedIds !== []) {
                foreach ($this->managedIssues($centre, $user, $management, $managedIds, [IssueStatus::Open->value], false, $now) as $issue) {
                    $sections['issue_attend'][] = ['type' => 'issue_attend', 'entity' => $issue, 'date' => $issue->getCreatedAt(), 'daysLeft' => null];
                }
                foreach ($this->managedIssues($centre, $user, $management, $managedIds, IssueStatus::openValues(), true, $now) as $issue) {
                    $sections['issue_overdue'][] = ['type' => 'issue_overdue', 'entity' => $issue, 'date' => $issue->getMaxDue() ?? $issue->getCreatedAt(), 'daysLeft' => null];
                }
            }

            // Las que tiene asignadas y siguen abiertas.
            /** @var list<Issue> $assigned */
            $assigned = $this->issues->createListQuery($centre, $user, false, [], new IssueListFilter(
                statuses: IssueStatus::openValues(),
                scope: IssueListFilter::SCOPE_ASSIGNED,
                sort: 'created',
                sortDir: 'asc',
            ))->getResult();
            foreach ($assigned as $issue) {
                $sections['issue_assigned'][] = ['type' => 'issue_assigned', 'entity' => $issue, 'date' => $issue->getCreatedAt(), 'daysLeft' => null];
            }

            // Lo que otras personas han hecho en las suyas en la última semana (puesta en proceso o cierre).
            $since = $now->modify('-7 days');
            /** @var list<Issue> $mine */
            $mine = $this->issues->createListQuery($centre, $user, false, [], new IssueListFilter(
                statuses: [IssueStatus::InProgress->value, IssueStatus::Solved->value, IssueStatus::NotApplicable->value, IssueStatus::Duplicate->value],
                scope: IssueListFilter::SCOPE_MINE,
                sort: 'created',
                sortDir: 'desc',
            ))->setMaxResults(50)->getResult();
            foreach ($mine as $issue) {
                if ($issue->getUpdatedAt() >= $since) {
                    $sections['issue_news'][] = ['type' => 'issue_news', 'entity' => $issue, 'date' => $issue->getUpdatedAt(), 'daysLeft' => null];
                }
            }
        }

        foreach ($sections as $key => $items) {
            if ($key !== 'prescription') {
                usort($items, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
                $sections[$key] = $items;
            }
        }

        return $this->grouped = array_filter($sections, static fn (array $items): bool => $items !== []);
    }

    /**
     * Incidencias ajenas que le toca gestionar, las más antiguas primero.
     *
     * @param list<string> $managedTypeIds
     * @param list<string> $statuses
     * @return list<Issue>
     */
    private function managedIssues(EducationalCentre $centre, Teacher $user, bool $management, array $managedTypeIds, array $statuses, bool $overdueOnly, \DateTimeImmutable $now): array
    {
        /** @var list<Issue> $issues */
        $issues = $this->issues->createListQuery($centre, $user, $management, $managedTypeIds, new IssueListFilter(
            statuses: $statuses,
            scope: IssueListFilter::SCOPE_MANAGED,
            overdueOnly: $overdueOnly,
            sort: 'created',
            sortDir: 'asc',
            today: $now,
        ))->getResult();

        return $issues;
    }

    public function getTotal(): int
    {
        return array_sum(array_map('count', $this->grouped()));
    }

    /**
     * Secciones a pintar, en orden de prioridad, con sus primeros elementos y cuántos quedan fuera.
     *
     * @return list<array{key: string, total: int, hidden: int, items: list<array{type: string, entity: IncidentReport|Sanction|SanctionTask|ResourceBooking|Issue|array<string, mixed>, date: \DateTimeImmutable, daysLeft: ?int}>}>
     */
    public function getSections(): array
    {
        $sections = [];
        foreach ($this->grouped() as $key => $items) {
            $sections[] = [
                'key'    => $key,
                'total'  => count($items),
                'hidden' => max(0, count($items) - self::MAX_PER_SECTION),
                'items'  => array_slice($items, 0, self::MAX_PER_SECTION),
            ];
        }

        return $sections;
    }

    /** Hay algo a punto de prescribir: la campana se tiñe de rojo, que es lo único que no puede esperar. */
    public function hasUrgent(): bool
    {
        return isset($this->grouped()['prescription']);
    }
}
