<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Teacher;
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

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PendingNotificationQueue $pendingNotificationQueue,
    ) {}

    /** @return list<array{type: 'report'|'sanction', entity: IncidentReport|Sanction, date: \DateTimeImmutable}> */
    public function getItems(): array
    {
        $centre = $this->tenantContext->getSelectedCentre();
        $user   = $this->getUser();
        if ($centre === null || !$user instanceof Teacher) {
            return [];
        }

        $queue = $this->pendingNotificationQueue->forViewer($centre, $user);

        $items = [];
        foreach ($queue['reports'] as $report) {
            $items[] = ['type' => 'report', 'entity' => $report, 'date' => $report->getOccurredAt()];
        }
        foreach ($queue['sanctions'] as $sanction) {
            $items[] = ['type' => 'sanction', 'entity' => $sanction, 'date' => $sanction->getCreatedAt()];
        }

        usort($items, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $items;
    }

    public function getTotal(): int
    {
        return count($this->getItems());
    }

    /** @return list<array{type: 'report'|'sanction', entity: IncidentReport|Sanction, date: \DateTimeImmutable}> */
    public function getVisibleItems(): array
    {
        return array_slice($this->getItems(), 0, self::MAX_ITEMS);
    }
}
