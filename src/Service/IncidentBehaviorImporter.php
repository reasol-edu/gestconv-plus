<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Repository\IncidentBehaviorCategoryRepository;
use App\Repository\IncidentBehaviorRepository;
use App\Service\Catalog\AbstractCatalogImporter;
use Doctrine\ORM\EntityManagerInterface;

class IncidentBehaviorImporter extends AbstractCatalogImporter
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly IncidentBehaviorCategoryRepository $categories,
        private readonly IncidentBehaviorRepository $behaviors,
    ) {
        parent::__construct($em);
    }

    protected function hasCategories(): bool
    {
        return true;
    }

    protected function itemsKey(): string
    {
        return 'behaviors';
    }

    protected function findExistingCategories(EducationalCentre $centre): iterable
    {
        return $this->categories->findByCentreOrdered($centre);
    }

    protected function countExistingCategories(EducationalCentre $centre): int
    {
        return $this->categories->countByCentre($centre);
    }

    protected function createCategory(EducationalCentre $centre, string $name, int $position): CatalogCategoryInterface
    {
        return (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    protected function applyCategoryExtra(CatalogCategoryInterface $category, array $catData): void
    {
        assert($category instanceof IncidentBehaviorCategory);

        $category->setSerious((bool) ($catData['serious'] ?? false));
    }

    protected function findExistingItemsForCategory(CatalogCategoryInterface $category): iterable
    {
        assert($category instanceof IncidentBehaviorCategory);

        return $this->behaviors->findByCategoryOrdered($category);
    }

    protected function countExistingItemsForCategory(CatalogCategoryInterface $category): int
    {
        assert($category instanceof IncidentBehaviorCategory);

        return $this->behaviors->countByCategory($category);
    }

    protected function createItem(
        EducationalCentre $centre,
        ?CatalogCategoryInterface $category,
        string $name,
        int $position,
    ): CatalogEntryInterface {
        assert($category instanceof IncidentBehaviorCategory);

        return (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition($position);
    }
}
