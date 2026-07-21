<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Repository\SanctionMeasureCategoryRepository;
use App\Repository\SanctionMeasureRepository;
use App\Service\Catalog\AbstractCatalogImporter;
use Doctrine\ORM\EntityManagerInterface;

class SanctionMeasureImporter extends AbstractCatalogImporter
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly SanctionMeasureCategoryRepository $categories,
        private readonly SanctionMeasureRepository $measures,
    ) {
        parent::__construct($em);
    }

    protected function hasCategories(): bool
    {
        return true;
    }

    protected function itemsKey(): string
    {
        return 'measures';
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
        return (new SanctionMeasureCategory())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    protected function findExistingItemsForCategory(CatalogCategoryInterface $category): iterable
    {
        assert($category instanceof SanctionMeasureCategory);

        return $this->measures->findByCategoryOrdered($category);
    }

    protected function countExistingItemsForCategory(CatalogCategoryInterface $category): int
    {
        assert($category instanceof SanctionMeasureCategory);

        return $this->measures->countByCategory($category);
    }

    protected function createItem(
        EducationalCentre $centre,
        ?CatalogCategoryInterface $category,
        string $name,
        int $position,
    ): CatalogEntryInterface {
        assert($category instanceof SanctionMeasureCategory);

        return (new SanctionMeasure())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition($position);
    }

    protected function applyItemExtra(CatalogEntryInterface $item, array $itemData): void
    {
        assert($item instanceof SanctionMeasure);

        $item->setHasDateRange((bool) ($itemData['has_date_range'] ?? false));
    }
}
