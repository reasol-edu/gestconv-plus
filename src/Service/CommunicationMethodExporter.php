<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Repository\CommunicationMethodRepository;

class CommunicationMethodExporter
{
    public function __construct(
        private readonly CommunicationMethodRepository $methods,
    ) {}

    /** @return array<string, mixed> */
    public function export(EducationalCentre $centre): array
    {
        $data = [
            'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'centre'      => $centre->getName(),
            'methods'     => [],
        ];

        foreach ($this->methods->findByCentreOrdered($centre) as $method) {
            $data['methods'][] = [
                'name'   => $method->getName(),
                'active' => $method->isActive(),
            ];
        }

        return $data;
    }
}
