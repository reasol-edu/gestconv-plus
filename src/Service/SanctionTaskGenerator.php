<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sanction;
use App\Entity\SanctionTask;
use Doctrine\ORM\EntityManagerInterface;

class SanctionTaskGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IncidentEmailNotifier $notifier,
        private readonly FeatureToggle $features,
    ) {}

    /**
     * Creates one SanctionTask per GroupTeacher currently assigned to the sanction's group,
     * snapshotting the group's teaching assignments at this moment. No-op if the sanction's
     * measures don't require dates, or if tasks already exist for this sanction (subsequent
     * changes to the group's teaching assignments are handled separately by "refrescar
     * materias", not by calling this method again). Also a no-op when the centre has the
     * "tareas de sanción" feature turned off (features.sanction_tasks).
     *
     * @return list<SanctionTask> the tasks created, empty if none were needed
     */
    public function generateFor(Sanction $sanction): array
    {
        if (!$sanction->requiresDates() || !$sanction->getTasks()->isEmpty()) {
            return [];
        }

        if (!$this->features->isEnabledForCentre(Feature::SanctionTasks, $sanction->getGroup()->getAcademicYear()->getEducationalCentre())) {
            return [];
        }

        $tasks = [];
        foreach ($sanction->getGroup()->getTeacherAssignments() as $groupTeacher) {
            $task = new SanctionTask($sanction, $groupTeacher);
            $this->em->persist($task);
            $tasks[] = $task;
        }

        if ($tasks !== []) {
            $this->em->flush();
            $this->notifier->sanctionTasksAssigned($sanction, $tasks);
        }

        return $tasks;
    }
}
