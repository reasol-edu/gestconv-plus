<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Repository\IncidentBehaviorCategoryRepository;
use App\Repository\IncidentBehaviorRepository;

class IncidentBehaviorExporter
{
    public function __construct(
        private readonly IncidentBehaviorCategoryRepository $categories,
        private readonly IncidentBehaviorRepository $behaviors,
    ) {}

    /** @return array<string, mixed> */
    public function export(EducationalCentre $centre): array
    {
        $data = [
            'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'centre'      => $centre->getName(),
            'categories'  => [],
        ];

        foreach ($this->categories->findByCentreOrdered($centre) as $category) {
            $categoryData = [
                'name'      => $category->getName(),
                'serious'   => $category->isSerious(),
                'behaviors' => [],
            ];

            foreach ($this->behaviors->findByCategoryOrdered($category) as $behavior) {
                $categoryData['behaviors'][] = [
                    'name'   => $behavior->getName(),
                    'active' => $behavior->isActive(),
                ];
            }

            $data['categories'][] = $categoryData;
        }

        return $data;
    }
}
