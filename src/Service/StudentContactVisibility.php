<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Student;
use App\Entity\Teacher;

/**
 * Determina si un docente puede ver los datos de contacto de la familia de un
 * estudiante (tutores legales, teléfonos y observaciones): equipo directivo,
 * comisión de convivencia, orientación y tutores/as de alguno de sus grupos.
 */
final class StudentContactVisibility
{
    public function isVisibleTo(Teacher $viewer, EducationalCentre $centre, Student $student): bool
    {
        if ($viewer->isAdmin()
            || $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer)) {
            return true;
        }

        foreach ($student->getGroups() as $group) {
            if ($group->getTutors()->contains($viewer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determina si el docente puede modificar los datos de contacto del estudiante:
     * únicamente si es tutor/a de alguno de los grupos del estudiante en el curso dado.
     * A diferencia de {@see isVisibleTo()}, no se extiende a equipo directivo, comisión
     * de convivencia ni orientación — esos perfiles editan desde el panel de administración.
     */
    public function canEditContact(Teacher $viewer, Student $student, AcademicYear $year): bool
    {
        foreach ($student->getGroups() as $group) {
            if ($group->getAcademicYear() === $year && $group->getTutors()->contains($viewer)) {
                return true;
            }
        }

        return false;
    }
}
