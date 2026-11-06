<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;

/**
 * Las personas a quienes se puede asignar una incidencia de un centro: los docentes de su curso activo (y, cuando existan,
 * el personal no docente asociado al centro).
 */
final class IssueAssignees
{
    public function __construct(
        private readonly TeacherRepository $teachers,
    ) {}

    /** @return list<Teacher> ordenadas por nombre */
    public function candidates(EducationalCentre $centre): array
    {
        $year = $centre->getActiveAcademicYear();

        return $year !== null ? array_values($this->teachers->findByAcademicYearOrderedByName($year)) : [];
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
