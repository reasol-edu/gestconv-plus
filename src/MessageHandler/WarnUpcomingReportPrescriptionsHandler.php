<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\IncidentReport;
use App\Entity\Teacher;
use App\Message\WarnUpcomingReportPrescriptionsMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\IncidentReportRepository;
use App\Service\AppSettingsInterface;
use App\Service\IncidentEmailNotifier;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class WarnUpcomingReportPrescriptionsHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly IncidentReportRepository $reports,
        private readonly AppSettingsInterface $settings,
        private readonly IncidentEmailNotifier $notifier,
    ) {}

    public function __invoke(WarnUpcomingReportPrescriptionsMessage $message): void
    {
        foreach ($this->centres->findAll() as $centre) {
            $autoPrescribeDays = $this->settings->getForCentre('notifications.report_auto_prescribe_days', $centre);
            if (!is_int($autoPrescribeDays) || $autoPrescribeDays <= 0) {
                continue;
            }

            $notifierSetting = $this->settings->getForCentre('notifications.report_notifier', $centre);
            $notifierSetting = is_string($notifierSetting) ? $notifierSetting : 'both';

            $now = new \DateTimeImmutable();

            /** @var array<string, Teacher> $teachersById */
            $teachersById = [];
            /** @var array<string, list<array{report: IncidentReport, daysRemaining: int}>> $itemsByTeacher */
            $itemsByTeacher = [];

            foreach ($this->reports->findPendingPrescription($centre) as $report) {
                $daysElapsed   = $now->diff($report->getOccurredAt())->days;
                $daysRemaining = $autoPrescribeDays - $daysElapsed;

                foreach ($this->recipientsFor($notifierSetting, $report) as $teacher) {
                    $warningDays = $this->settings->getForTeacherInCentre(
                        'notifications.report_prescription_warning_days',
                        $teacher,
                        $centre,
                    );
                    if (!is_int($warningDays) || $warningDays <= 0 || $daysRemaining > $warningDays) {
                        continue;
                    }

                    $teacherId                     = $teacher->getId()->toRfc4122();
                    $teachersById[$teacherId]       = $teacher;
                    $itemsByTeacher[$teacherId][]   = ['report' => $report, 'daysRemaining' => $daysRemaining];
                }
            }

            foreach ($itemsByTeacher as $teacherId => $items) {
                $this->notifier->reportsNearingPrescription($teachersById[$teacherId], $items);
            }
        }
    }

    /** @return list<Teacher> */
    private function recipientsFor(string $notifierSetting, IncidentReport $report): array
    {
        /** @var array<string, Teacher> $recipients */
        $recipients = [];

        if ($notifierSetting === 'report_teacher' || $notifierSetting === 'both') {
            $teacher                                    = $report->getRegisteredBy();
            $recipients[$teacher->getId()->toRfc4122()] = $teacher;
        }

        if ($notifierSetting === 'group_tutor' || $notifierSetting === 'both') {
            foreach ($report->getGroup()->getTutors() as $teacher) {
                $recipients[$teacher->getId()->toRfc4122()] = $teacher;
            }
        }

        return array_values($recipients);
    }
}
