<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use App\Repository\LocationOptionCategoryRepository;
use App\Repository\LocationOptionRepository;
use Doctrine\ORM\EntityManagerInterface;

class LocationOptionImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationOptionCategoryRepository $categories,
        private readonly LocationOptionRepository $options,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{categories: int, options: int}
     */
    public function import(array $data, EducationalCentre $centre, bool $replaceExisting = false): array
    {
        $stats = [
            'categories' => 0,
            'options'    => 0,
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
                $category = (new LocationOptionCategory())
                    ->setEducationalCentre($centre)
                    ->setName($catName)
                    ->setPosition($nextCategoryPosition++);
                $this->em->persist($category);
                $existingCategories[mb_strtolower($catName)] = $category;
                $stats['categories']++;
            }

            $existingOptions = [];
            $nextOptionPosition = $this->options->countByCategory($category);
            foreach ($this->options->findByCategoryOrdered($category) as $o) {
                $existingOptions[mb_strtolower($o->getName())] = $o;
            }

            foreach ((array) ($catData['options'] ?? []) as $optionData) {
                if (!is_array($optionData)) {
                    continue;
                }
                $optionNameRaw = $optionData['name'] ?? null;
                $optionName    = is_string($optionNameRaw) ? trim($optionNameRaw) : '';
                if ($optionName === '') {
                    continue;
                }

                $option = $existingOptions[mb_strtolower($optionName)] ?? null;
                if ($option === null) {
                    $option = (new LocationOption())
                        ->setEducationalCentre($centre)
                        ->setCategory($category)
                        ->setName($optionName)
                        ->setPosition($nextOptionPosition++);
                    $this->em->persist($option);
                    $existingOptions[mb_strtolower($optionName)] = $option;
                    $stats['options']++;
                }

                $option->setActive((bool) ($optionData['active'] ?? true));
            }
        }

        $this->em->flush();

        return $stats;
    }
}
