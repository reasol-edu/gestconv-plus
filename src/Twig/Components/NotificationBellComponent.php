<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\Teacher;
use App\Repository\DailyNoteRepository;
use App\Repository\SanctionTaskRepository;
use App\Service\PendingNotificationQueue;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class NotificationBellComponent extends AbstractController
{
    use DefaultActionTrait;

    public const MAX_ITEMS = 8;

    /** @var list<array{type: 'report'|'sanction'|'task'|'note_threshold', entity: IncidentReport|Sanction|SanctionTask|array<string, mixed>, date: \DateTimeImmutable}>|null */
    private ?array $items = null;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PendingNotificationQueue $pendingNotificationQueue,
        private readonly SanctionTaskRepository $sanctionTaskRepository,
        private readonly DailyNoteRepository $dailyNoteRepository,
    ) {}

    /** @return list<array{type: 'report'|'sanction'|'task'|'note_threshold', entity: IncidentReport|Sanction|SanctionTask|array<string, mixed>, date: \DateTimeImmutable}> */
    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $centre = $this->tenantContext->getSelectedCentre();
        $user   = $this->getUser();
        if ($centre === null || !$user instanceof Teacher) {
            return $this->items = [];
        }

        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            return $this->items = [];
        }

        $queue = $this->pendingNotificationQueue->forViewer($centre, $user, $year);

        $items = [];
        foreach ($queue['reports'] as $report) {
            $items[] = ['type' => 'report', 'entity' => $report, 'date' => $report->getOccurredAt()];
        }
        foreach ($queue['sanctions'] as $sanction) {
            $items[] = ['type' => 'sanction', 'entity' => $sanction, 'date' => $sanction->getCreatedAt()];
        }
        foreach ($this->sanctionTaskRepository->findPendingForTeacher($centre, $user, $year) as $task) {
            $items[] = [
                'type'   => 'task',
                'entity' => $task,
                'date'   => $task->getSanction()->getEffectiveFrom() ?? $task->getSanction()->getCreatedAt(),
            ];
        }
        foreach ($this->dailyNoteRepository->findStudentsAtThreshold($centre, $user, $year) as $row) {
            $items[] = [
                'type'   => 'note_threshold',
                'entity' => $row,
                'date'   => $row['lastNoteAt'] ?? new \DateTimeImmutable('@0'),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $this->items = $items;
    }

    public function getTotal(): int
    {
        return count($this->getItems());
    }

    /** @return list<array{type: 'report'|'sanction'|'task'|'note_threshold', entity: IncidentReport|Sanction|SanctionTask|array<string, mixed>, date: \DateTimeImmutable}> */
    public function getVisibleItems(): array
    {
        return array_slice($this->getItems(), 0, self::MAX_ITEMS);
    }
}
