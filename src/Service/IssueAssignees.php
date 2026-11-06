<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;

/**
 * Las personas a quienes se puede asignar una incidencia de un centro, o que pueden ser responsables de un tipo: los
 * docentes de su curso activo y el personal no docente asociado al centro.
 */
final class IssueAssignees
{
    public function __construct(
        private readonly TeacherRepository $teachers,
    ) {}

    /** @return list<Teacher> los docentes del curso y el personal no docente del centro, ordenados por nombre */
    public function candidates(EducationalCentre $centre): array
    {
        $year   = $centre->getActiveAcademicYear();
        $people = $year !== null ? array_values($this->teachers->findByAcademicYearOrderedByName($year)) : [];
        foreach ($this->teachers->findNonTeachingByCentre($centre) as $person) {
            if ($person->isActive()) {
                $people[] = $person;
            }
        }
        usort($people, static fn (Teacher $a, Teacher $b): int => strcmp(
            mb_strtolower($a->getName()->getLastName() . ' ' . $a->getName()->getFirstName()),
            mb_strtolower($b->getName()->getLastName() . ' ' . $b->getName()->getFirstName()),
        ));

        return $people;
    }

    /** La persona con ese id, si se le puede asignar una incidencia de ese centro. */
    public function find(EducationalCentre $centre, string $id): ?Teacher
    {
        foreach ($this->candidates($centre) as $candidate) {
            if ($candidate->getId()->toRfc4122() === strtolower($id)) {
                return $candidate;
            }
        }

        return null;
    }
}
