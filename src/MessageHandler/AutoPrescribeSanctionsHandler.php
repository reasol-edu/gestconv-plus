<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AutoPrescribeSanctionsMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\SanctionRepository;
use App\Service\AppSettingsInterface;
use App\Service\IncidentEmailNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Marks as prescribed the sanctions that were not notified to the family within the centre's
 * "notifications.sanction_auto_prescribe_days". A value of 0 (the default) disables it.
 */
#[AsMessageHandler]
final class AutoPrescribeSanctionsHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly SanctionRepository $sanctions,
        private readonly AppSettingsInterface $settings,
        private readonly IncidentEmailNotifier $notifier,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(AutoPrescribeSanctionsMessage $message): void
    {
        foreach ($this->centres->findAll() as $centre) {
            $days = $this->settings->getForCentre('notifications.sanction_auto_prescribe_days', $centre);
            if (!is_int($days) || $days <= 0) {
                continue;
            }

            $cutoff = $this->clock->now()->modify("-{$days} days");

            foreach ($this->sanctions->findEligibleForAutoPrescription($centre, $cutoff) as $sanction) {
                $sanction->setPrescribedAt($this->clock->now());
                $this->notifier->sanctionAutoPrescribed($sanction);
            }
        }

        $this->em->flush();
    }
}
