<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class LocationOptionSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function seedForCentre(EducationalCentre $centre): void
    {
        $config = Yaml::parseFile($this->projectDir . '/config/location_options.yaml');
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

            $category = (new LocationOptionCategory())
                ->setEducationalCentre($centre)
                ->setName($catName)
                ->setPosition($catPosition);

            $this->em->persist($category);

            $rawOptions = is_array($catData['options'] ?? null) ? $catData['options'] : [];
            foreach ($rawOptions as $optionPosition => $optionName) {
                if (!is_string($optionName) || !is_int($optionPosition)) {
                    continue;
                }

                $option = (new LocationOption())
                    ->setEducationalCentre($centre)
                    ->setCategory($category)
                    ->setName($optionName)
                    ->setPosition($optionPosition)
                    ->setActive(true);

                $this->em->persist($option);
            }
        }
    }
}
