<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\SchoolEventType;
use App\Repository\SchoolEventTypeRepository;
use App\Service\Catalog\AbstractCatalogExporter;
use Symfony\Component\Clock\ClockInterface;

class SchoolEventTypeExporter extends AbstractCatalogExporter
{
    public function __construct(
        private readonly SchoolEventTypeRepository $types,
        ClockInterface $clock,
    ) {
        parent::__construct($clock);
    }

    protected function itemsKey(): string
    {
        return 'types';
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsForCentre(EducationalCentre $centre): iterable
    {
        return $this->types->findByCentreOrdered($centre);
    }

    protected function itemExtra(CatalogEntryInterface $item): array
    {
        assert($item instanceof SchoolEventType);

        return ['color' => $item->getColor()];
    }
}
