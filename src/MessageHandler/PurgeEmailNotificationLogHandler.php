<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeEmailNotificationLogMessage;
use App\Repository\EmailNotificationLogRepository;
use App\Service\AppSettingsInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeEmailNotificationLogHandler
{
    public function __construct(
        private readonly EmailNotificationLogRepository $logs,
        private readonly AppSettingsInterface $settings,
    ) {}

    public function __invoke(PurgeEmailNotificationLogMessage $message): void
    {
        $days = $this->settings->getGlobal('notifications.log_retention_days');
        if (!is_int($days) || $days <= 0) {
            return;
        }

        $cutoff = new \DateTimeImmutable("-{$days} days");
        $this->logs->deleteOlderThan($cutoff);
    }
}
