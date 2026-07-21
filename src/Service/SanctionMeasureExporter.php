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
use App\Service\Catalog\AbstractCatalogExporter;

class SanctionMeasureExporter extends AbstractCatalogExporter
{
    public function __construct(
        private readonly SanctionMeasureCategoryRepository $categories,
        private readonly SanctionMeasureRepository $measures,
    ) {}

    protected function itemsKey(): string
    {
        return 'measures';
    }

    protected function hasCategories(): bool
    {
        return true;
    }

    protected function categoriesFor(EducationalCentre $centre): iterable
    {
        return $this->categories->findByCentreOrdered($centre);
    }

    protected function itemsForCategory(CatalogCategoryInterface $category): iterable
    {
        assert($category instanceof SanctionMeasureCategory);

        return $this->measures->findByCategoryOrdered($category);
    }

    protected function itemExtra(CatalogEntryInterface $item): array
    {
        assert($item instanceof SanctionMeasure);

        return ['has_date_range' => $item->hasDateRange()];
    }
}
