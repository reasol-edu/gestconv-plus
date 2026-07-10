<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Repository\LocationOptionCategoryRepository;
use App\Repository\LocationOptionRepository;

class LocationOptionExporter
{
    public function __construct(
        private readonly LocationOptionCategoryRepository $categories,
        private readonly LocationOptionRepository $options,
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
                'name'    => $category->getName(),
                'options' => [],
            ];

            foreach ($this->options->findByCategoryOrdered($category) as $option) {
                $categoryData['options'][] = [
                    'name'   => $option->getName(),
                    'active' => $option->isActive(),
                ];
            }

            $data['categories'][] = $categoryData;
        }

        return $data;
    }
}
