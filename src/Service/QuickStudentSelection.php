<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\Teacher;
use App\Repository\GroupRepository;

/**
 * Grupos que ofrece la «selección rápida» de estudiantes (formularios de parte y de nota): primero los que
 * la persona imparte o tutoriza y, aparte, el resto del centro. El ajuste `display.quick_student_selection`
 * (global, de centro y de docente; activado por defecto) la desactiva: entonces no hay grupos que mostrar.
 */
final class QuickStudentSelection
{
    public function __construct(
        private readonly AppSettingsInterface $settings,
        private readonly GroupRepository $groups,
    ) {}

    /** @return array{mine: list<Group>, others: list<Group>} */
    public function groupsFor(Teacher $user, EducationalCentre $centre): array
    {
        if ($this->settings->getForTeacherInCentre('display.quick_student_selection', $user, $centre) === false) {
            return ['mine' => [], 'others' => []];
        }

        $year = $centre->getActiveAcademicYear();
        $mine = $year !== null ? array_values($this->groups->findTaughtOrTutoredInYear($year, $user)) : [];

        $mineIds = array_map(static fn (Group $g): string => $g->getId()->toRfc4122(), $mine);
        $others  = array_values(array_filter(
            $this->groups->findByActiveYearOfCentreOrderedByName($centre),
            static fn (Group $g): bool => !in_array($g->getId()->toRfc4122(), $mineIds, true),
        ));

        return ['mine' => $mine, 'others' => $others];
    }
}
