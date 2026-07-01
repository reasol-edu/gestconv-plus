<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Repository\SanctionMeasureCategoryRepository;
use App\Repository\SanctionMeasureRepository;
use Doctrine\ORM\EntityManagerInterface;

class SanctionMeasureImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SanctionMeasureCategoryRepository $categories,
        private readonly SanctionMeasureRepository $measures,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{categories: int, measures: int}
     */
    public function import(array $data, EducationalCentre $centre, bool $replaceExisting = false): array
    {
        $stats = [
            'categories' => 0,
            'measures'   => 0,
        ];

        if ($replaceExisting) {
            foreach ($this->categories->findByCentreOrdered($centre) as $existing) {
                $this->em->remove($existing);
            }
            $this->em->flush();
        }

        $existingCategories = [];
        $nextCategoryPosition = $this->categories->countByCentre($centre);
        foreach ($this->categories->findByCentreOrdered($centre) as $c) {
            $existingCategories[mb_strtolower($c->getName())] = $c;
        }

        foreach ((array) ($data['categories'] ?? []) as $catData) {
            if (!is_array($catData)) {
                continue;
            }
            $nameRaw = $catData['name'] ?? null;
            $catName = is_string($nameRaw) ? trim($nameRaw) : '';
            if ($catName === '') {
                continue;
            }

            $category = $existingCategories[mb_strtolower($catName)] ?? null;
            if ($category === null) {
                $category = (new SanctionMeasureCategory())
                    ->setEducationalCentre($centre)
                    ->setName($catName)
                    ->setPosition($nextCategoryPosition++);
                $this->em->persist($category);
                $existingCategories[mb_strtolower($catName)] = $category;
                $stats['categories']++;
            }

            $existingMeasures = [];
            $nextMeasurePosition = $this->measures->countByCategory($category);
            foreach ($this->measures->findByCategoryOrdered($category) as $m) {
                $existingMeasures[mb_strtolower($m->getName())] = $m;
            }

            foreach ((array) ($catData['measures'] ?? []) as $measureData) {
                if (!is_array($measureData)) {
                    continue;
                }
                $measureNameRaw = $measureData['name'] ?? null;
                $measureName    = is_string($measureNameRaw) ? trim($measureNameRaw) : '';
                if ($measureName === '') {
                    continue;
                }

                $measure = $existingMeasures[mb_strtolower($measureName)] ?? null;
                if ($measure === null) {
                    $measure = (new SanctionMeasure())
                        ->setEducationalCentre($centre)
                        ->setCategory($category)
                        ->setName($measureName)
                        ->setPosition($nextMeasurePosition++);
                    $this->em->persist($measure);
                    $existingMeasures[mb_strtolower($measureName)] = $measure;
                    $stats['measures']++;
                }

                $measure->setHasDateRange((bool) ($measureData['has_date_range'] ?? false));
                $measure->setActive((bool) ($measureData['active'] ?? true));
            }
        }

        $this->em->flush();

        return $stats;
    }
}
