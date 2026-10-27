<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Repository\ResourceCategoryRepository;
use App\Repository\SharedResourceRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Exportación del catálogo de recursos compartidos: categorías (con sus reglas y los usuarios de sus responsables)
 * y, dentro de cada una, sus recursos. Mismo formato que lee {@see ResourceCatalogImporter}.
 */
class ResourceCatalogExporter
{
    public function __construct(
        private readonly ResourceCategoryRepository $categories,
        private readonly SharedResourceRepository $resources,
        private readonly ClockInterface $clock,
    ) {}

    /** @return array<string, mixed> */
    public function export(EducationalCentre $centre): array
    {
        $resourcesByCategory = [];
        foreach ($this->resources->findByCentreOrdered($centre) as $resource) {
            $resourcesByCategory[$resource->getCategory()->getId()->toRfc4122()][] = $resource;
        }

        $categories = [];
        foreach ($this->categories->findByCentreOrdered($centre) as $category) {
            $managers = array_map(
                static fn (Teacher $teacher): string => $teacher->getUsername(),
                $category->getManagers()->toArray(),
            );
            sort($managers);

            $categories[] = [
                'name'              => $category->getName(),
                'color'             => $category->getColor(),
                'active'            => $category->isActive(),
                'restricted'        => $category->isRestricted(),
                'requires_approval' => $category->requiresApproval(),
                'max_advance_days'  => $category->getMaxAdvanceDays(),
                'managers'          => $managers,
                'resources'         => array_map(
                    static fn (SharedResource $resource): array => [
                        'name'        => $resource->getName(),
                        'description' => $resource->getDescription(),
                        'location'    => $resource->getLocation(),
                        'active'      => $resource->isActive(),
                    ],
                    $resourcesByCategory[$category->getId()->toRfc4122()] ?? [],
                ),
            ];
        }

        return [
            'exported_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'centre'      => $centre->getName(),
            'categories'  => $categories,
        ];
    }
}
