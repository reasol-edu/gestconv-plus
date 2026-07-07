<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CommunicationMethod;
use App\Entity\EducationalCentre;
use App\Repository\CommunicationMethodRepository;
use App\Repository\CommunicationRepository;
use Doctrine\ORM\EntityManagerInterface;

class CommunicationMethodImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CommunicationMethodRepository $methods,
        private readonly CommunicationRepository $communications,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{methods: int}
     */
    public function import(array $data, EducationalCentre $centre, bool $replaceExisting = false): array
    {
        $stats = [
            'methods' => 0,
        ];

        if ($replaceExisting) {
            foreach ($this->methods->findByCentreOrdered($centre) as $existing) {
                if ($this->communications->countByMethod($existing) > 0) {
                    continue;
                }
                $this->em->remove($existing);
            }
            $this->em->flush();
        }

        $existingMethods = [];
        $nextPosition    = $this->methods->countByCentre($centre);
        foreach ($this->methods->findByCentreOrdered($centre) as $m) {
            $existingMethods[mb_strtolower($m->getName())] = $m;
        }

        foreach ((array) ($data['methods'] ?? []) as $methodData) {
            if (!is_array($methodData)) {
                continue;
            }
            $nameRaw = $methodData['name'] ?? null;
            $name    = is_string($nameRaw) ? trim($nameRaw) : '';
            if ($name === '') {
                continue;
            }

            $method = $existingMethods[mb_strtolower($name)] ?? null;
            if ($method === null) {
                $method = (new CommunicationMethod())
                    ->setEducationalCentre($centre)
                    ->setName($name)
                    ->setPosition($nextPosition++);
                $this->em->persist($method);
                $existingMethods[mb_strtolower($name)] = $method;
                $stats['methods']++;
            }

            $method->setActive((bool) ($methodData['active'] ?? true));
        }

        $this->em->flush();

        return $stats;
    }
}
