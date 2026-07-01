<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Repository\SanctionMeasureCategoryRepository;
use App\Repository\SanctionMeasureRepository;

class SanctionMeasureExporter
{
    public function __construct(
        private readonly SanctionMeasureCategoryRepository $categories,
        private readonly SanctionMeasureRepository $measures,
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
                'name'     => $category->getName(),
                'measures' => [],
            ];

            foreach ($this->measures->findByCategoryOrdered($category) as $measure) {
                $categoryData['measures'][] = [
                    'name'           => $measure->getName(),
                    'has_date_range' => $measure->hasDateRange(),
                    'active'         => $measure->isActive(),
                ];
            }

            $data['categories'][] = $categoryData;
        }

        return $data;
    }
}
