<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class SanctionMeasureSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function seedForCentre(EducationalCentre $centre): void
    {
        $config = Yaml::parseFile($this->projectDir . '/config/sanction_measures.yaml');
        if (!is_array($config)) {
            return;
        }

        $rawCategories = $config['categories'] ?? [];
        if (!is_array($rawCategories)) {
            return;
        }

        foreach ($rawCategories as $catPosition => $catData) {
            if (!is_array($catData) || !is_int($catPosition)) {
                continue;
            }

            $catName = is_string($catData['name'] ?? null) ? $catData['name'] : '';
            if ($catName === '') {
                continue;
            }

            $category = (new SanctionMeasureCategory())
                ->setEducationalCentre($centre)
                ->setName($catName)
                ->setPosition($catPosition);

            $this->em->persist($category);

            $rawMeasures = is_array($catData['measures'] ?? null) ? $catData['measures'] : [];
            foreach ($rawMeasures as $measurePosition => $measureData) {
                if (!is_array($measureData) || !is_int($measurePosition)) {
                    continue;
                }

                $measureName  = is_string($measureData['name'] ?? null) ? $measureData['name'] : '';
                $hasDateRange = (bool) ($measureData['has_date_range'] ?? false);

                if ($measureName === '') {
                    continue;
                }

                $measure = (new SanctionMeasure())
                    ->setEducationalCentre($centre)
                    ->setCategory($category)
                    ->setName($measureName)
                    ->setHasDateRange($hasDateRange)
                    ->setPosition($measurePosition)
                    ->setActive(true);

                $this->em->persist($measure);
            }
        }
    }
}
