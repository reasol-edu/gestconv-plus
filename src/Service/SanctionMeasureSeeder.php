<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Service\Catalog\AbstractCatalogSeeder;

final class SanctionMeasureSeeder extends AbstractCatalogSeeder
{
    protected function configFile(): string
    {
        return 'sanction_measures.yaml';
    }

    protected function hasCategories(): bool
    {
        return true;
    }

    protected function itemsKey(): string
    {
        return 'measures';
    }

    protected function createCategory(EducationalCentre $centre, string $name, int $position): CatalogCategoryInterface
    {
        return (new SanctionMeasureCategory())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    protected function parseItem(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $name = is_string($raw['name'] ?? null) ? $raw['name'] : '';

        return [$name, ['has_date_range' => (bool) ($raw['has_date_range'] ?? false)]];
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

    protected function applyItemExtra(CatalogEntryInterface $item, array $extra): void
    {
        assert($item instanceof SanctionMeasure);

        $item->setHasDateRange((bool) ($extra['has_date_range'] ?? false));
    }
}
