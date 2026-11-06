<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use App\Entity\LocationScope;
use App\Service\Catalog\AbstractCatalogSeeder;

final class LocationOptionSeeder extends AbstractCatalogSeeder
{
    protected function configFile(): string
    {
        return 'location_options.yaml';
    }

    protected function hasCategories(): bool
    {
        return true;
    }

    protected function itemsKey(): string
    {
        return 'options';
    }

    protected function createCategory(EducationalCentre $centre, string $name, int $position): CatalogCategoryInterface
    {
        return (new LocationOptionCategory())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    protected function applyCategoryExtra(CatalogCategoryInterface $category, array $catData): void
    {
        assert($category instanceof LocationOptionCategory);

        // En el catálogo por defecto, «General» (las ubicaciones propias de un parte) es solo para partes.
        $raw = $catData['scope'] ?? null;
        $category->setScope((is_string($raw) ? LocationScope::tryFrom($raw) : null) ?? LocationScope::All);
    }

    protected function createItem(
        EducationalCentre $centre,
        ?CatalogCategoryInterface $category,
        string $name,
        int $position,
    ): CatalogEntryInterface {
        assert($category instanceof LocationOptionCategory);

        return (new LocationOption())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition($position);
    }
}
