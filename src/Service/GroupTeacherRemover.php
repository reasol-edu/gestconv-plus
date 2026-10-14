<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GroupTeacher;
use App\Repository\SanctionTaskRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Elimina la materia que un docente imparte en un grupo (GroupTeacher). Si tiene tareas de
 * sanción asociadas, solo se borra cuando quien llama lo pide expresamente (`$withTasks`): las
 * tareas —con su contenido y adjuntos— se eliminan con ella, y no se pueden recuperar.
 */
final class GroupTeacherRemover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanctionTaskRepository $sanctionTasks,
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * @return int|null número de tareas borradas con la materia, o null si no se ha borrado nada
     *                  porque tiene tareas y no se pidió eliminarlas
     */
    public function remove(GroupTeacher $assignment, bool $withTasks): ?int
    {
        $tasks = $this->sanctionTasks->findByGroupTeacher($assignment);
        if ($tasks !== [] && !$withTasks) {
            return null;
        }

        $group     = $assignment->getGroup();
        $teacherId = $assignment->getTeacher()->getId()->toRfc4122();
        $groupId   = $group->getId()->toRfc4122();
        $entityId  = $assignment->getId()->toRfc4122();

        foreach ($tasks as $task) {
            $task->getSanction()->getTasks()->removeElement($task);
            $this->em->remove($task);
        }
        $group->removeTeacherAssignment($assignment);
        $this->em->flush();

        $this->activityLog->log('group_teacher.removed', [
            'entityId'  => $entityId,
            'teacherId' => $teacherId,
            'groupId'   => $groupId,
            'taskCount' => count($tasks),
        ]);

        return count($tasks);
    }
}
