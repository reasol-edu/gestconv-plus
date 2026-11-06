<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssueType;
use App\Entity\Teacher;
use App\Repository\IssueTypeRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Quién gestiona y quién ve las incidencias: la dirección (administradores de la plataforma y del centro) gestiona todas;
 * los responsables de un tipo, las de ese tipo y las de todos los tipos que cuelgan de él; y quien la registró, la suya.
 */
final class IssueAccess implements ResetInterface
{
    /** @var array<string, list<string>> ids de los tipos que gestiona cada docente en cada centro (null = todos) */
    private array $managedTypeIds = [];

    public function __construct(
        private readonly IssueTypeRepository $types,
    ) {}

    public function isManagement(Teacher $teacher, EducationalCentre $centre): bool
    {
        return $teacher->isAdmin() || $centre->getAdmins()->contains($teacher);
    }

    /** Es responsable del tipo (propio o heredado de un ascendente) o pertenece a la dirección. */
    public function managesType(Teacher $teacher, IssueType $type): bool
    {
        if ($this->isManagement($teacher, $type->getEducationalCentre())) {
            return true;
        }
        foreach ($type->getEffectiveManagers() as $manager) {
            if ($manager->getId()->equals($teacher->getId())) {
                return true;
            }
        }

        return false;
    }

    public function manages(Teacher $teacher, Issue $issue): bool
    {
        return $this->managesType($teacher, $issue->getType());
    }

    public function isReporter(Teacher $teacher, Issue $issue): bool
    {
        return $issue->getReporter()->getId()->equals($teacher->getId());
    }

    /** Es la persona a quien se ha asignado la incidencia. */
    public function isAssignee(Teacher $teacher, Issue $issue): bool
    {
        return $issue->getAssignee()?->getId()->equals($teacher->getId()) === true;
    }

    /** Puede cambiar su prioridad y su estado: quien la gestiona y la persona asignada. */
    public function canAct(Teacher $teacher, Issue $issue): bool
    {
        return $this->manages($teacher, $issue) || $this->isAssignee($teacher, $issue);
    }

    /** Quien la registró, la persona asignada, sus responsables y la dirección. */
    public function canView(Teacher $teacher, Issue $issue): bool
    {
        return $this->isReporter($teacher, $issue) || $this->isAssignee($teacher, $issue) || $this->manages($teacher, $issue);
    }

    /** Es dirección o responsable de algún tipo del centro (gestiona incidencias ajenas). */
    public function hasManagerRole(Teacher $teacher, EducationalCentre $centre): bool
    {
        return $this->isManagement($teacher, $centre) || $this->managedTypeIds($teacher, $centre) !== [];
    }

    /**
     * Los ids de los tipos cuyas incidencias gestiona el docente fuera de la dirección: los que tiene asignados y todos los
     * que cuelgan de ellos. Para filtrar los listados y las estadísticas.
     *
     * @return list<string>
     */
    public function managedTypeIds(Teacher $teacher, EducationalCentre $centre): array
    {
        $key = $teacher->getId()->toRfc4122() . '|' . $centre->getId()->toRfc4122();
        if (isset($this->managedTypeIds[$key])) {
            return $this->managedTypeIds[$key];
        }

        $ids = [];
        foreach ($this->types->findByCentre($centre) as $type) {
            foreach ($type->getEffectiveManagers() as $manager) {
                if ($manager->getId()->equals($teacher->getId())) {
                    $ids[] = $type->getId()->toRfc4122();

                    break;
                }
            }
        }

        return $this->managedTypeIds[$key] = $ids;
    }

    /**
     * Las personas a las que avisar de una incidencia: sus responsables (los del tipo y los de sus ascendentes) o, si no
     * hay ninguno, la dirección del centro.
     *
     * @return list<Teacher>
     */
    public function responsibles(Issue $issue): array
    {
        $managers = $issue->getType()->getEffectiveManagers();
        if ($managers !== []) {
            return $managers;
        }

        return array_values($issue->getEducationalCentre()->getAdmins()->toArray());
    }

    public function reset(): void
    {
        $this->managedTypeIds = [];
    }
}
