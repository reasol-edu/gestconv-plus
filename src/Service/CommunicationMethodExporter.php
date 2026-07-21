<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Repository\CommunicationMethodRepository;
use App\Service\Catalog\AbstractCatalogExporter;

class CommunicationMethodExporter extends AbstractCatalogExporter
{
    public function __construct(
        private readonly CommunicationMethodRepository $methods,
    ) {}

    protected function itemsKey(): string
    {
        return 'methods';
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsForCentre(EducationalCentre $centre): iterable
    {
        return $this->methods->findByCentreOrdered($centre);
    }
}
