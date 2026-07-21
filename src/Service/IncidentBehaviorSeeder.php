<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Service\Catalog\AbstractCatalogSeeder;

final class IncidentBehaviorSeeder extends AbstractCatalogSeeder
{
    protected function configFile(): string
    {
        return 'incident_behaviors.yaml';
    }

    protected function hasCategories(): bool
    {
        return true;
    }

    protected function itemsKey(): string
    {
        return 'behaviors';
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
