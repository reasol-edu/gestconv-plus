<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\IncidentBehaviorCategory;
use App\Repository\IncidentBehaviorCategoryRepository;
use App\Repository\IncidentBehaviorRepository;
use App\Service\Catalog\AbstractCatalogExporter;

class IncidentBehaviorExporter extends AbstractCatalogExporter
{
    public function __construct(
        private readonly IncidentBehaviorCategoryRepository $categories,
        private readonly IncidentBehaviorRepository $behaviors,
    ) {}

    protected function itemsKey(): string
    {
        return 'behaviors';
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
        assert($category instanceof IncidentBehaviorCategory);

        return $this->behaviors->findByCategoryOrdered($category);
    }

    protected function categoryExtra(CatalogCategoryInterface $category): array
    {
        assert($category instanceof IncidentBehaviorCategory);

        return ['serious' => $category->isSerious()];
    }
}
