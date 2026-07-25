<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SanctionTask;
use App\Entity\Teacher;
use App\Message\SanctionTaskReminderMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\SanctionTaskRepository;
use App\Service\AppSettingsInterface;
use App\Service\IncidentEmailNotifier;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SanctionTaskReminderHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly SanctionTaskRepository $tasks,
        private readonly AppSettingsInterface $settings,
        private readonly IncidentEmailNotifier $notifier,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(SanctionTaskReminderMessage $message): void
    {
        foreach ($this->centres->findAll() as $centre) {
            $reminderDays = $this->settings->getForCentre('notifications.sanction_task_reminder_days', $centre);
            if (!is_int($reminderDays) || $reminderDays <= 0) {
                continue;
            }

            $from = $this->clock->now()->setTime(0, 0, 0);
            $to   = $from->modify("+{$reminderDays} days");

            /** @var array<string, Teacher> $teachersById */
            $teachersById = [];
            /** @var array<string, list<SanctionTask>> $tasksByTeacher */
            $tasksByTeacher = [];

            foreach ($this->tasks->findIncompleteStartingWithin($centre, $from, $to) as $task) {
                $teacher                      = $task->getGroupTeacher()->getTeacher();
                $teacherId                    = $teacher->getId()->toRfc4122();
                $teachersById[$teacherId]     = $teacher;
                $tasksByTeacher[$teacherId][] = $task;
            }

            foreach ($tasksByTeacher as $teacherId => $items) {
                $this->notifier->sanctionTasksReminder($teachersById[$teacherId], $items);
            }
        }
    }
}
