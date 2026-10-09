<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Message\WarnUpcomingSanctionPrescriptionsMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\SanctionRepository;
use App\Service\AppSettingsInterface;
use App\Service\IncidentEmailNotifier;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Daily digest per teacher with the sanctions that are about to prescribe. Recipients follow the
 * centre's "notifications.sanction_notifier" setting; each one has its own warning threshold
 * ("notifications.sanction_prescription_warning_days", 0 = no warning).
 */
#[AsMessageHandler]
final class WarnUpcomingSanctionPrescriptionsHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly SanctionRepository $sanctions,
        private readonly AppSettingsInterface $settings,
        private readonly IncidentEmailNotifier $notifier,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(WarnUpcomingSanctionPrescriptionsMessage $message): void
    {
        foreach ($this->centres->findAll() as $centre) {
            $autoPrescribeDays = $this->settings->getForCentre('notifications.sanction_auto_prescribe_days', $centre);
            if (!is_int($autoPrescribeDays) || $autoPrescribeDays <= 0) {
                continue;
            }

            $notifierSetting = $this->settings->getForCentre('notifications.sanction_notifier', $centre);
            $notifierSetting = is_string($notifierSetting) ? $notifierSetting : 'both';

            $now = $this->clock->now();

            /** @var array<string, Teacher> $teachersById */
            $teachersById = [];
            /** @var array<string, list<array{sanction: Sanction, daysRemaining: int}>> $itemsByTeacher */
            $itemsByTeacher = [];

            foreach ($this->sanctions->findPendingPrescription($centre) as $sanction) {
                $daysElapsed   = (int) $sanction->getCreatedAt()->diff($now)->days;
                $daysRemaining = $autoPrescribeDays - $daysElapsed;

                foreach ($this->recipientsFor($notifierSetting, $sanction) as $teacher) {
                    $warningDays = $this->settings->getForTeacherInCentre(
                        'notifications.sanction_prescription_warning_days',
                        $teacher,
                        $centre,
                    );
                    if (!is_int($warningDays) || $warningDays <= 0 || $daysRemaining > $warningDays) {
                        continue;
                    }

                    $teacherId                    = $teacher->getId()->toRfc4122();
                    $teachersById[$teacherId]     = $teacher;
                    $itemsByTeacher[$teacherId][] = ['sanction' => $sanction, 'daysRemaining' => $daysRemaining];
                }
            }

            foreach ($itemsByTeacher as $teacherId => $items) {
                $this->notifier->sanctionsNearingPrescription($teachersById[$teacherId], $items);
            }
        }
    }

    /** @return list<Teacher> */
    private function recipientsFor(string $notifierSetting, Sanction $sanction): array
    {
        /** @var array<string, Teacher> $recipients */
        $recipients = [];

        if ($notifierSetting === 'report_teacher' || $notifierSetting === 'both') {
            $teacher                                    = $sanction->getRegisteredBy();
            $recipients[$teacher->getId()->toRfc4122()] = $teacher;
        }

        if ($notifierSetting === 'group_tutor' || $notifierSetting === 'both') {
            foreach ($sanction->getGroup()->getTutors() as $teacher) {
                $recipients[$teacher->getId()->toRfc4122()] = $teacher;
            }
        }

        return array_values($recipients);
    }
}
