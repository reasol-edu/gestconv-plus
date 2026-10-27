<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;
use App\Entity\SchoolEvent;
use App\Repository\StudentRepository;

/**
 * Reparte los estudiantes asociados a una actividad entre los grupos de esta (uno que esté en varios grupos
 * aparece en cada uno). Solo devuelve los grupos con algún estudiante asociado.
 */
class SchoolEventRosterBuilder
{
    public function __construct(
        private readonly StudentRepository $students,
    ) {}

    /** @return list<SchoolEventRoster> por nombre de grupo */
    public function build(SchoolEvent $event): array
    {
        if ($event->getStudents()->isEmpty() || $event->getGroups()->isEmpty()) {
            return [];
        }

        $associated = [];
        foreach ($event->getStudents() as $student) {
            $associated[$student->getId()->toRfc4122()] = true;
        }

        $groups = array_values($event->getGroups()->toArray());
        usort($groups, static fn (Group $a, Group $b): int => strnatcasecmp($a->getName(), $b->getName()));

        // Una sola consulta: los estudiantes de los grupos (ya ordenados) con los grupos de la actividad en que están.
        $byGroup = [];
        foreach ($this->students->findByGroupsOrdered($groups) as $student) {
            if (!isset($associated[$student->getId()->toRfc4122()])) {
                continue;
            }
            foreach ($student->getGroups() as $group) {
                $byGroup[$group->getId()->toRfc4122()][] = $student;
            }
        }

        $rosters = [];
        foreach ($groups as $group) {
            $students = $byGroup[$group->getId()->toRfc4122()] ?? [];
            if ($students !== []) {
                $rosters[] = new SchoolEventRoster($group, $students);
            }
        }

        return $rosters;
    }
}
