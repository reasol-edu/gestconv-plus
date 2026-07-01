<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Repository\IncidentBehaviorCategoryRepository;
use App\Repository\IncidentBehaviorRepository;
use Doctrine\ORM\EntityManagerInterface;

class IncidentBehaviorImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IncidentBehaviorCategoryRepository $categories,
        private readonly IncidentBehaviorRepository $behaviors,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{categories: int, behaviors: int}
     */
    public function import(array $data, EducationalCentre $centre, bool $replaceExisting = false): array
    {
        $stats = [
            'categories' => 0,
            'behaviors'  => 0,
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
                $category = (new IncidentBehaviorCategory())
                    ->setEducationalCentre($centre)
                    ->setName($catName)
                    ->setPosition($nextCategoryPosition++);
                $this->em->persist($category);
                $existingCategories[mb_strtolower($catName)] = $category;
                $stats['categories']++;
            }

            $category->setSerious((bool) ($catData['serious'] ?? false));

            $existingBehaviors = [];
            $nextBehaviorPosition = $this->behaviors->countByCategory($category);
            foreach ($this->behaviors->findByCategoryOrdered($category) as $b) {
                $existingBehaviors[mb_strtolower($b->getName())] = $b;
            }

            foreach ((array) ($catData['behaviors'] ?? []) as $behaviorData) {
                if (!is_array($behaviorData)) {
                    continue;
                }
                $behaviorNameRaw = $behaviorData['name'] ?? null;
                $behaviorName    = is_string($behaviorNameRaw) ? trim($behaviorNameRaw) : '';
                if ($behaviorName === '') {
                    continue;
                }

                $behavior = $existingBehaviors[mb_strtolower($behaviorName)] ?? null;
                if ($behavior === null) {
                    $behavior = (new IncidentBehavior())
                        ->setEducationalCentre($centre)
                        ->setCategory($category)
                        ->setName($behaviorName)
                        ->setPosition($nextBehaviorPosition++);
                    $this->em->persist($behavior);
                    $existingBehaviors[mb_strtolower($behaviorName)] = $behavior;
                    $stats['behaviors']++;
                }

                $behavior->setActive((bool) ($behaviorData['active'] ?? true));
            }
        }

        $this->em->flush();

        return $stats;
    }
}
