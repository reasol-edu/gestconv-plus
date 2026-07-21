<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\CommunicationMethod;
use App\Entity\EducationalCentre;
use App\Repository\CommunicationMethodRepository;
use App\Repository\CommunicationRepository;
use App\Service\Catalog\AbstractCatalogImporter;
use Doctrine\ORM\EntityManagerInterface;

class CommunicationMethodImporter extends AbstractCatalogImporter
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly CommunicationMethodRepository $methods,
        private readonly CommunicationRepository $communications,
    ) {
        parent::__construct($em);
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsKey(): string
    {
        return 'methods';
    }

    protected function findExistingItemsForCentre(EducationalCentre $centre): iterable
    {
        return $this->methods->findByCentreOrdered($centre);
    }

    protected function countExistingItemsForCentre(EducationalCentre $centre): int
    {
        return $this->methods->countByCentre($centre);
    }

    protected function createItem(
        EducationalCentre $centre,
        ?CatalogCategoryInterface $category,
        string $name,
        int $position,
    ): CatalogEntryInterface {
        return (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    protected function canRemove(object $entity): bool
    {
        assert($entity instanceof CommunicationMethod);

        return $this->communications->countByMethod($entity) === 0;
    }
}
