<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Repository\GroupTeacherRepository;
use App\Repository\TimeSlotRepository;

/**
 * Cuando un docente nuevo sustituye a otro (una baja, un cambio de plaza…), hereda lo que tenía el sustituido en el
 * curso: los perfiles del centro (administración, comisión de convivencia, orientación y coordinación de actividades),
 * las tutorías, las materias que imparte en cada grupo y las guardias. Es una **copia**: el docente sustituido
 * conserva lo suyo (lo puede retirar la dirección cuando quiera). No se copia la condición de administrador
 * global, que es de la cuenta y no del curso.
 */
final class TeacherReplacement
{
    public function __construct(
        private readonly GroupRepository $groups,
        private readonly GroupTeacherRepository $groupTeachers,
        private readonly TimeSlotRepository $timeSlots,
    ) {}

    /**
     * No hace flush. $from y $to tienen que pertenecer al curso.
     *
     * @return array{profiles: int, tutorships: int, subjects: int, guards: int} lo que se ha copiado (sin contar lo que $to ya tenía)
     */
    public function copyAssignments(Teacher $from, Teacher $to, AcademicYear $year): array
    {
        $copied = ['profiles' => 0, 'tutorships' => 0, 'subjects' => 0, 'guards' => 0];
        if ($from === $to) {
            return $copied;
        }

        $centre = $year->getEducationalCentre();

        // Perfiles del centro: se copian en las listas en las que está el sustituido.
        $profiles = [
            [$centre->getAdmins(), $centre->addAdmin(...)],
            [$centre->getCommitteeMembers(), $centre->addCommitteeMember(...)],
            [$centre->getCounselors(), $centre->addCounselor(...)],
            [$centre->getActivityCoordinators(), $centre->addActivityCoordinator(...)],
        ];
        foreach ($profiles as [$members, $add]) {
            if ($members->contains($from) && !$members->contains($to)) {
                $add($to);
                ++$copied['profiles'];
            }
        }

        // Tutorías del curso.
        foreach ($this->groups->findTutoredByActiveYear($centre, $from, $year) as $group) {
            if (!$group->getTutors()->contains($to)) {
                $group->addTutor($to);
                ++$copied['tutorships'];
            }
        }

        // Materias: cada par grupo-materia del sustituido.
        foreach ($this->groupTeachers->findByTeacherAndAcademicYearOrdered($from, $year) as $assignment) {
            $group = $assignment->getGroup();
            if (!$group->hasTeacherSubject($to, $assignment->getSubject())) {
                $group->addTeacher($to, $assignment->getSubject());
                ++$copied['subjects'];
            }
        }

        // Guardias: los tramos del curso en los que el sustituido está de guardia.
        foreach ($this->timeSlots->findGuardedByTeacherInYear($from, $year) as $slot) {
            if (!$slot->getGuards()->contains($to)) {
                $slot->addGuard($to);
                ++$copied['guards'];
            }
        }

        return $copied;
    }
}
