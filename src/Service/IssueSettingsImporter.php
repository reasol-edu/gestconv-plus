<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\IssuePriority;
use App\Entity\IssueType;
use App\Entity\Teacher;
use App\Repository\IssuePriorityRepository;
use App\Repository\IssueTypeRepository;
use App\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importación de la configuración de incidencias desde el formato de {@see IssueSettingsExporter}.
 *
 * - Los tipos se emparejan por su ruta (nombres desde la raíz, sin distinguir mayúsculas) y las prioridades por nombre.
 * - «Sustituir» actualiza lo emparejado con los datos del fichero (el estado y, si se pide, los responsables de los tipos;
 *   el color y los plazos de las prioridades); «ignorar» lo deja como está y crea solo lo que falta. Ninguno borra nada:
 *   hay incidencias que usan los tipos y las prioridades.
 * - Elegir un tipo importa también sus ascendentes (el nodo cuelga de ellos); los ascendentes que ya existen no se tocan
 *   salvo que se hayan elegido también.
 * - Los responsables se buscan por usuario entre los docentes del curso activo y no se crean: los que no existen se omiten.
 * - Las prioridades nuevas van al final de la lista, en el orden del fichero.
 *
 * @phpstan-type IssueTypeNode array{index: int, parent: ?int, depth: int, name: string, path: string, active: bool, managers: list<array{username: string, name: ?string}>}
 * @phpstan-type IssuePriorityNode array{index: int, name: string, color: string, recommended_days: int, max_days: int}
 * @phpstan-type IssueSettingsFile array{centre: ?string, types: list<IssueTypeNode>, priorities: list<IssuePriorityNode>}
 * @phpstan-type IssueSettingsPreview array{types: array<int, string>, priorities: array<int, string>, unresolved: array<string, ?string>}
 */
class IssueSettingsImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueTypeRepository $types,
        private readonly IssuePriorityRepository $priorities,
        private readonly TeacherRepository $teachers,
    ) {}

    /**
     * Valida y normaliza el contenido de un fichero (los tipos se aplanan en profundidad, con la posición del padre).
     * Devuelve null si no es una configuración de incidencias de GestConv+.
     *
     * @return IssueSettingsFile|null
     */
    public function parse(mixed $decoded): ?array
    {
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== IssueSettingsExporter::FORMAT) {
            return null;
        }
        $version = $decoded['version'] ?? 1;
        if (!is_int($version) || $version < 1 || $version > IssueSettingsExporter::VERSION) {
            return null;
        }

        $types = [];
        $this->flatten((array) ($decoded['types'] ?? []), null, 0, '', $types);

        $priorities = [];
        foreach ((array) ($decoded['priorities'] ?? []) as $data) {
            if (!is_array($data) || ($name = $this->cleanText($data['name'] ?? null, 100)) === null) {
                continue;
            }
            $color        = $data['color'] ?? null;
            $priorities[] = [
                'index'            => count($priorities),
                'name'             => $name,
                'color'            => is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? strtolower($color) : '#6b7280',
                'recommended_days' => $this->days($data['recommended_days'] ?? 0),
                'max_days'         => $this->days($data['max_days'] ?? 0),
            ];
        }

        $centre = $decoded['centre'] ?? null;

        return ['centre' => is_array($centre) ? $this->cleanText($centre['name'] ?? null, 255) : null, 'types' => $types, 'priorities' => $priorities];
    }

    /**
     * Qué pasaría con cada tipo y prioridad del fichero en este centro («new» o «exists»), y qué responsables no existen.
     *
     * @param IssueSettingsFile $parsed
     * @return IssueSettingsPreview
     */
    public function preview(array $parsed, EducationalCentre $centre): array
    {
        $existingTypes      = $this->existingTypes($centre);
        $existingPriorities = $this->existingPriorities($centre);
        $available          = $this->availableTeachers($centre);

        $types      = [];
        $unresolved = [];
        foreach ($parsed['types'] as $node) {
            $types[$node['index']] = isset($existingTypes[$node['path']]) ? 'exists' : 'new';
            foreach ($node['managers'] as $manager) {
                if (!isset($available[mb_strtolower($manager['username'])])) {
                    $unresolved[$manager['username']] ??= $manager['name'];
                }
            }
        }
        $priorities = [];
        foreach ($parsed['priorities'] as $node) {
            $priorities[$node['index']] = isset($existingPriorities[mb_strtolower($node['name'])]) ? 'exists' : 'new';
        }
        ksort($unresolved);

        return ['types' => $types, 'priorities' => $priorities, 'unresolved' => $unresolved];
    }

    /**
     * @param IssueSettingsFile $parsed
     * @param list<int>         $typeIndexes     tipos elegidos (posición en el fichero aplanado)
     * @param list<int>         $priorityIndexes prioridades elegidas (posición en el fichero)
     */
    public function import(array $parsed, EducationalCentre $centre, array $typeIndexes, array $priorityIndexes, IssueSettingsImportOptions $options): IssueSettingsImportResult
    {
        $existingTypes      = $this->existingTypes($centre);
        $existingPriorities = $this->existingPriorities($centre);
        $available          = $this->availableTeachers($centre);
        /** @var array<string, string> $unresolved */
        $unresolved = [];
        $stats      = ['tc' => 0, 'tu' => 0, 'ti' => 0, 'pc' => 0, 'pu' => 0, 'pi' => 0];

        // Elegir un tipo importa también sus ascendentes.
        $byIndex = [];
        foreach ($parsed['types'] as $node) {
            $byIndex[$node['index']] = $node;
        }
        $wanted = [];
        foreach ($typeIndexes as $index) {
            for ($i = $index; $i !== null && isset($byIndex[$i]); $i = $byIndex[$i]['parent']) {
                $wanted[$i] = $i === $index || ($wanted[$i] ?? false);
            }
        }
        ksort($wanted);

        /** @var array<int, IssueType> $resolved nodo del fichero => tipo del centro */
        $resolved = [];
        foreach ($wanted as $index => $explicit) {
            $node   = $byIndex[$index];
            $type   = $existingTypes[$node['path']] ?? null;
            $isNew  = $type === null;
            if ($isNew) {
                $parent = $node['parent'] !== null ? ($resolved[$node['parent']] ?? null) : null;
                $type   = (new IssueType())->setEducationalCentre($centre)->setName($node['name'])->setParent($parent)->setActive($node['active']);
                $this->em->persist($type);
                $existingTypes[$node['path']] = $type;
                $stats['tc']++;
            } elseif ($explicit && $options->replace) {
                $type->setActive($node['active']);
                $stats['tu']++;
            } elseif ($explicit) {
                $stats['ti']++;
            }
            $resolved[$index] = $type;

            // Los responsables se aplican a los tipos nuevos y a los existentes elegidos y sustituidos.
            if ($options->managers && ($isNew || ($explicit && $options->replace))) {
                $this->syncManagers($type, $node['managers'], $available, $unresolved);
            }
        }

        $nextPosition = $this->priorities->nextPosition($centre);
        foreach ($priorityIndexes as $index) {
            $node = $parsed['priorities'][$index] ?? null;
            if ($node === null) {
                continue;
            }
            $key      = mb_strtolower($node['name']);
            $priority = $existingPriorities[$key] ?? null;
            if ($priority === null) {
                $priority = (new IssuePriority())->setEducationalCentre($centre)->setName($node['name'])->setPosition($nextPosition++);
                $this->em->persist($priority);
                $existingPriorities[$key] = $priority;
                $stats['pc']++;
            } elseif ($options->replace) {
                $stats['pu']++;
            } else {
                $stats['pi']++;

                continue;
            }
            $priority->setColor($node['color'])->setRecommendedDays($node['recommended_days'])->setMaxDays($node['max_days']);
        }

        $this->em->flush();

        ksort($unresolved);

        return new IssueSettingsImportResult($stats['tc'], $stats['tu'], $stats['ti'], $stats['pc'], $stats['pu'], $stats['pi'], array_values($unresolved));
    }

    /**
     * Aplana el árbol en profundidad, dejando en cada nodo la posición de su padre y su ruta en minúsculas.
     *
     * @param array<mixed>         $nodes
     * @param list<IssueTypeNode>  $out
     */
    private function flatten(array $nodes, ?int $parent, int $depth, string $parentPath, array &$out): void
    {
        foreach ($nodes as $data) {
            if (!is_array($data) || ($name = $this->cleanText($data['name'] ?? null, 255)) === null) {
                continue;
            }

            $managers = [];
            foreach ((array) ($data['managers'] ?? []) as $reference) {
                $username = TeacherReference::username($reference);
                if ($username !== null) {
                    $managers[mb_strtolower($username)] = ['username' => $username, 'name' => TeacherReference::name($reference)];
                }
            }

            $path  = ($parentPath === '' ? '' : $parentPath . ' › ') . mb_strtolower($name);
            $index = count($out);
            $out[] = [
                'index'    => $index,
                'parent'   => $parent,
                'depth'    => $depth,
                'name'     => $name,
                'path'     => $path,
                'active'   => (bool) ($data['active'] ?? true),
                'managers' => array_values($managers),
            ];
            $this->flatten((array) ($data['children'] ?? []), $index, $depth + 1, $path, $out);
        }
    }

    /**
     * Deja como responsables exactamente los del fichero que existen en el curso activo.
     *
     * @param list<array{username: string, name: ?string}> $managers
     * @param array<string, Teacher>                      $available
     * @param array<string, string>                       $unresolved
     */
    private function syncManagers(IssueType $type, array $managers, array $available, array &$unresolved): void
    {
        $wanted = [];
        foreach ($managers as $manager) {
            $teacher = $available[mb_strtolower($manager['username'])] ?? null;
            if ($teacher === null) {
                $unresolved[$manager['username']] = $manager['name'] !== null ? sprintf('%s (%s)', $manager['username'], $manager['name']) : $manager['username'];

                continue;
            }
            $wanted[$teacher->getId()->toRfc4122()] = $teacher;
        }

        foreach ($type->getManagers()->toArray() as $current) {
            if (!isset($wanted[$current->getId()->toRfc4122()])) {
                $type->removeManager($current);
            }
        }
        foreach ($wanted as $teacher) {
            $type->addManager($teacher);
        }
    }

    /** @return array<string, IssueType> los tipos del centro por ruta en minúsculas */
    private function existingTypes(EducationalCentre $centre): array
    {
        $existing = [];
        foreach ($this->types->findByCentre($centre) as $type) {
            $existing[mb_strtolower($type->getPath(' › '))] = $type;
        }

        return $existing;
    }

    /** @return array<string, IssuePriority> */
    private function existingPriorities(EducationalCentre $centre): array
    {
        $existing = [];
        foreach ($this->priorities->findByCentreOrdered($centre) as $priority) {
            $existing[mb_strtolower($priority->getName())] = $priority;
        }

        return $existing;
    }

    /** @return array<string, Teacher> docentes del curso activo por usuario en minúsculas */
    private function availableTeachers(EducationalCentre $centre): array
    {
        $year      = $centre->getActiveAcademicYear();
        $available = [];
        foreach ($year !== null ? $this->teachers->findByAcademicYearOrderedByName($year) : [] as $teacher) {
            $available[mb_strtolower($teacher->getUsername())] = $teacher;
        }

        return $available;
    }

    private function days(mixed $raw): int
    {
        return is_int($raw) ? max(0, min(365, $raw)) : 0;
    }

    private function cleanText(mixed $raw, int $maxLength): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);

        return $raw === '' ? null : mb_substr($raw, 0, $maxLength);
    }
}
