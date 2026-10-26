<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\SchoolEventType;
use App\Repository\SchoolEventRepository;
use App\Repository\SchoolEventTypeRepository;
use App\Service\Catalog\AbstractCatalogImporter;
use Doctrine\ORM\EntityManagerInterface;

class SchoolEventTypeImporter extends AbstractCatalogImporter
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly SchoolEventTypeRepository $types,
        private readonly SchoolEventRepository $events,
    ) {
        parent::__construct($em);
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsKey(): string
    {
        return 'types';
    }

    protected function findExistingItemsForCentre(EducationalCentre $centre): iterable
    {
        return $this->types->findByCentreOrdered($centre);
    }

    protected function countExistingItemsForCentre(EducationalCentre $centre): int
    {
        return $this->types->countByCentre($centre);
    }

    protected function createItem(
        EducationalCentre $centre,
        ?CatalogCategoryInterface $category,
        string $name,
        int $position,
    ): CatalogEntryInterface {
        return (new SchoolEventType())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    protected function applyItemExtra(CatalogEntryInterface $item, array $itemData): void
    {
        assert($item instanceof SchoolEventType);

        $item->setColor(SchoolEventTypeColor::normalize($itemData['color'] ?? null));
    }

    protected function canRemove(object $entity): bool
    {
        assert($entity instanceof SchoolEventType);

        return $this->events->countByType($entity) === 0;
    }
}
