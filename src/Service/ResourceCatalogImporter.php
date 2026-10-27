<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\ResourceCategory;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Repository\ResourceBookingRepository;
use App\Repository\ResourceCategoryRepository;
use App\Repository\SharedResourceRepository;
use App\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importación del catálogo de recursos compartidos desde el formato de {@see ResourceCatalogExporter}.
 *
 * El emparejamiento con lo existente se hace por nombre en minúsculas (categoría en el centro, recurso dentro de su
 * categoría): si coincide se actualiza y si no se crea. Los responsables se buscan por usuario entre los docentes del
 * curso activo; los que no existen allí se ignoran.
 */
class ResourceCatalogImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResourceCategoryRepository $categories,
        private readonly SharedResourceRepository $resources,
        private readonly ResourceBookingRepository $bookings,
        private readonly TeacherRepository $teachers,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{categories: int, resources: int}
     */
    public function import(array $data, EducationalCentre $centre, bool $replaceExisting = false): array
    {
        if ($replaceExisting) {
            $this->deleteExisting($centre);
        }

        $year      = $centre->getActiveAcademicYear();
        $available = [];
        foreach ($year !== null ? $this->teachers->findByAcademicYearOrderedByName($year) : [] as $teacher) {
            $available[mb_strtolower($teacher->getUsername())] = $teacher;
        }

        $existingCategories = [];
        foreach ($this->categories->findByCentreOrdered($centre) as $category) {
            $existingCategories[mb_strtolower($category->getName())] = $category;
        }

        $existingResources = [];
        foreach ($this->resources->findByCentreOrdered($centre) as $resource) {
            $existingResources[$resource->getCategory()->getId()->toRfc4122()][mb_strtolower($resource->getName())] = $resource;
        }

        $stats = ['categories' => 0, 'resources' => 0];

        foreach ((array) ($data['categories'] ?? []) as $categoryData) {
            if (!is_array($categoryData)) {
                continue;
            }
            $name = $this->cleanText($categoryData['name'] ?? null, 200);
            if ($name === null) {
                continue;
            }

            $category = $existingCategories[mb_strtolower($name)] ?? null;
            if ($category === null) {
                $category = (new ResourceCategory())->setEducationalCentre($centre)->setName($name);
                $this->em->persist($category);
                $existingCategories[mb_strtolower($name)] = $category;
                $stats['categories']++;
            }

            $advance = $categoryData['max_advance_days'] ?? null;
            $category
                ->setColor(SchoolEventTypeColor::normalize(is_string($categoryData['color'] ?? null) ? $categoryData['color'] : null))
                ->setActive((bool) ($categoryData['active'] ?? true))
                ->setRestricted((bool) ($categoryData['restricted'] ?? false))
                ->setRequiresApproval((bool) ($categoryData['requires_approval'] ?? false))
                ->setMaxAdvanceDays(is_int($advance) && $advance >= 0 ? min($advance, 365) : null);

            $this->applyManagers($category, (array) ($categoryData['managers'] ?? []), $available);

            // El id se genera al persistir, así que también existe en las categorías recién creadas.
            $key = $category->getId()->toRfc4122();
            foreach ((array) ($categoryData['resources'] ?? []) as $resourceData) {
                if (!is_array($resourceData)) {
                    continue;
                }
                $resourceName = $this->cleanText($resourceData['name'] ?? null, 200);
                if ($resourceName === null) {
                    continue;
                }

                $resource = $existingResources[$key][mb_strtolower($resourceName)] ?? null;
                if ($resource === null) {
                    $resource = (new SharedResource())->setCategory($category)->setName($resourceName);
                    $this->em->persist($resource);
                    $existingResources[$key][mb_strtolower($resourceName)] = $resource;
                    $stats['resources']++;
                }

                $resource
                    ->setDescription($this->cleanText($resourceData['description'] ?? null, 2000))
                    ->setLocation($this->cleanText($resourceData['location'] ?? null, 255))
                    ->setActive((bool) ($resourceData['active'] ?? true));
            }
        }

        $this->em->flush();

        return $stats;
    }

    /**
     * Borra lo que no tiene reservas: las categorías sin ninguna y, en las demás, los recursos que no tienen.
     */
    private function deleteExisting(EducationalCentre $centre): void
    {
        foreach ($this->categories->findByCentreOrdered($centre) as $category) {
            if ($this->bookings->countByCategory($category) === 0) {
                $this->em->remove($category);

                continue;
            }

            foreach ($this->resources->findByCentreOrdered($centre) as $resource) {
                if ($resource->getCategory() === $category && $this->bookings->countByResource($resource) === 0) {
                    $this->em->remove($resource);
                }
            }
        }

        $this->em->flush();
    }

    /**
     * Sustituye los responsables por los del fichero que existan entre los docentes del curso.
     *
     * @param array<mixed>           $usernames
     * @param array<string, Teacher> $available docentes del curso por usuario en minúsculas
     */
    private function applyManagers(ResourceCategory $category, array $usernames, array $available): void
    {
        $wanted = [];
        foreach ($usernames as $username) {
            if (is_string($username) && isset($available[mb_strtolower(trim($username))])) {
                $teacher = $available[mb_strtolower(trim($username))];
                $wanted[$teacher->getId()->toRfc4122()] = $teacher;
            }
        }

        foreach ($category->getManagers()->toArray() as $manager) {
            if (!isset($wanted[$manager->getId()->toRfc4122()])) {
                $category->removeManager($manager);
            }
        }
        foreach ($wanted as $teacher) {
            $category->addManager($teacher);
        }
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
