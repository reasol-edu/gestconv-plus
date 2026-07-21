<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\EducationalCentre;
use App\Entity\LocationOptionCategory;
use App\Repository\LocationOptionCategoryRepository;
use App\Repository\LocationOptionRepository;
use App\Service\Catalog\AbstractCatalogExporter;

class LocationOptionExporter extends AbstractCatalogExporter
{
    public function __construct(
        private readonly LocationOptionCategoryRepository $categories,
        private readonly LocationOptionRepository $options,
    ) {}

    protected function itemsKey(): string
    {
        return 'options';
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
        assert($category instanceof LocationOptionCategory);

        return $this->options->findByCategoryOrdered($category);
    }
}
