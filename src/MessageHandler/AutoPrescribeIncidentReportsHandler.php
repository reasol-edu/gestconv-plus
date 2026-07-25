<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AutoPrescribeIncidentReportsMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\IncidentReportRepository;
use App\Service\AppSettingsInterface;
use App\Service\IncidentEmailNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AutoPrescribeIncidentReportsHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly IncidentReportRepository $reports,
        private readonly AppSettingsInterface $settings,
        private readonly IncidentEmailNotifier $notifier,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(AutoPrescribeIncidentReportsMessage $message): void
    {
        foreach ($this->centres->findAll() as $centre) {
            $days = $this->settings->getForCentre('notifications.report_auto_prescribe_days', $centre);
            if (!is_int($days) || $days <= 0) {
                continue;
            }

            $cutoff = $this->clock->now()->modify("-{$days} days");

            foreach ($this->reports->findEligibleForAutoPrescription($centre, $cutoff) as $report) {
                $report->setPrescribedAt($this->clock->now());
                $this->notifier->reportAutoPrescribed($report);
            }
        }

        $this->em->flush();
    }
}
