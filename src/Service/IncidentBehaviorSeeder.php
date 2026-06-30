<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class IncidentBehaviorSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function seedForCentre(EducationalCentre $centre): void
    {
        $config     = Yaml::parseFile($this->projectDir . '/config/incident_behaviors.yaml');
        $categories = $config['categories'] ?? [];

        foreach ($categories as $catPosition => $catData) {
            $category = (new IncidentBehaviorCategory())
                ->setEducationalCentre($centre)
                ->setName($catData['name'])
                ->setSerious((bool) $catData['serious'])
                ->setPosition($catPosition);

            $this->em->persist($category);

            foreach ($catData['behaviors'] as $behaviorPosition => $behaviorName) {
                $behavior = (new IncidentBehavior())
                    ->setEducationalCentre($centre)
                    ->setCategory($category)
                    ->setName($behaviorName)
                    ->setPosition($behaviorPosition)
                    ->setActive(true);

                $this->em->persist($behavior);
            }
        }
    }
}
