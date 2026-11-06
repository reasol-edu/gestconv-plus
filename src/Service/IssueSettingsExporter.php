<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\IssuePriority;
use App\Entity\IssueType;
use App\Entity\Teacher;
use App\Repository\IssuePriorityRepository;
use App\Repository\IssueTypeRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Exportación de la configuración de incidencias de un centro: las prioridades (de más a menos importante, con su color
 * y sus plazos) y la lista jerárquica de tipos con los responsables de cada nodo. Mismo formato que lee
 * {@see IssueSettingsImporter}.
 *
 * Los responsables van como `{username, name}` (ver {@see TeacherReference}): el usuario IdEA los identifica al importar
 * en otro curso o despliegue. De cada nodo se exportan solo los responsables que tiene asignados él; los de sus
 * ascendentes se heredan por la propia jerarquía.
 */
class IssueSettingsExporter
{
    public const FORMAT  = 'gestconv.issue_settings';
    public const VERSION = 1;

    public function __construct(
        private readonly IssueTypeRepository $types,
        private readonly IssuePriorityRepository $priorities,
        private readonly ClockInterface $clock,
    ) {}

    /** @return array<string, mixed> */
    public function export(EducationalCentre $centre): array
    {
        $byParent = [];
        foreach ($this->types->findByCentre($centre) as $type) {
            $byParent[$type->getParent()?->getId()->toRfc4122() ?? ''][] = $type;
        }

        return [
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'exported_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'centre'      => ['code' => $centre->getCode(), 'name' => $centre->getName()],
            'priorities'  => array_map(
                static fn (IssuePriority $priority): array => [
                    'name'             => $priority->getName(),
                    'color'            => $priority->getColor(),
                    'recommended_days' => $priority->getRecommendedDays(),
                    'max_days'         => $priority->getMaxDays(),
                ],
                $this->priorities->findByCentreOrdered($centre),
            ),
            'types'       => $this->branch($byParent, ''),
        ];
    }

    /**
     * @param array<string, list<IssueType>> $byParent
     * @return list<array<string, mixed>>
     */
    private function branch(array $byParent, string $parentId): array
    {
        $nodes = [];
        foreach ($byParent[$parentId] ?? [] as $type) {
            $managers = array_map(static fn (Teacher $teacher): array => TeacherReference::export($teacher), $type->getManagers()->toArray());
            usort($managers, static fn (array $a, array $b): int => strcmp($a['username'], $b['username']));

            $nodes[] = [
                'name'     => $type->getName(),
                'active'   => $type->isActive(),
                'managers' => $managers,
                'children' => $this->branch($byParent, $type->getId()->toRfc4122()),
            ];
        }

        return $nodes;
    }
}
