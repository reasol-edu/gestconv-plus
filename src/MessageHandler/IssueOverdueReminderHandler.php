<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Issue;
use App\Entity\Teacher;
use App\Message\IssueOverdueReminderMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\IssueRepository;
use App\Service\Feature;
use App\Service\FeatureToggle;
use App\Service\IncidentEmailNotifier;
use App\Service\IssueAccess;
use App\Service\NonWorkingDayChecker;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Recordatorio diario, en días lectivos, de las incidencias abiertas que han pasado su plazo máximo: un aviso por persona
 * responsable con todas las suyas (o una línea en su resumen diario, si lo tiene configurado). Cada quien puede
 * desactivarlo con el ajuste «recibir» del aviso `issue_overdue`.
 */
#[AsMessageHandler]
final class IssueOverdueReminderHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly IssueRepository $issues,
        private readonly IssueAccess $access,
        private readonly IncidentEmailNotifier $notifier,
        private readonly NonWorkingDayChecker $nonWorkingDays,
        private readonly FeatureToggle $features,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(IssueOverdueReminderMessage $message): void
    {
        $today = $this->clock->now()->setTime(0, 0);

        foreach ($this->centres->findAll() as $centre) {
            $year = $centre->getActiveAcademicYear();
            if ($year === null || !$this->features->isEnabledForCentre(Feature::Issues, $centre) || $this->nonWorkingDays->isNonWorkingDay($year, $today)) {
                continue;
            }

            /** @var array<string, Teacher> $teachers */
            $teachers = [];
            /** @var array<string, non-empty-list<Issue>> $byTeacher */
            $byTeacher = [];
            foreach ($this->issues->findOverdue($centre, $today) as $issue) {
                // Sus responsables y la persona a quien está asignada.
                /** @var array<string, Teacher> $people */
                $people = [];
                foreach ([...$this->access->responsibles($issue), $issue->getAssignee()] as $person) {
                    if ($person !== null) {
                        $people[$person->getId()->toRfc4122()] = $person;
                    }
                }
                foreach ($people as $id => $person) {
                    $teachers[$id]    = $person;
                    $byTeacher[$id][] = $issue;
                }
            }

            foreach ($byTeacher as $id => $issues) {
                $this->notifier->issueOverdue($teachers[$id], $issues);
            }
        }
    }
}
