<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CommunicationMethod;
use App\Entity\EducationalCentre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final class CommunicationMethodSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function seedForCentre(EducationalCentre $centre): void
    {
        $config = Yaml::parseFile($this->projectDir . '/config/communication_methods.yaml');
        if (!is_array($config)) {
            return;
        }

        $rawMethods = $config['methods'] ?? [];
        if (!is_array($rawMethods)) {
            return;
        }

        foreach ($rawMethods as $position => $name) {
            if (!is_string($name) || !is_int($position)) {
                continue;
            }

            $method = (new CommunicationMethod())
                ->setEducationalCentre($centre)
                ->setName($name)
                ->setPosition($position)
                ->setActive(true);

            $this->em->persist($method);
        }
    }
}
