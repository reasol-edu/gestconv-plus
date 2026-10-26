<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\SchoolEventType;
use App\Service\Catalog\AbstractCatalogSeeder;

final class SchoolEventTypeSeeder extends AbstractCatalogSeeder
{
    protected function configFile(): string
    {
        return 'school_event_types.yaml';
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsKey(): string
    {
        return 'types';
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

    protected function parseItem(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        return [is_string($raw['name'] ?? null) ? $raw['name'] : '', ['color' => $raw['color'] ?? null]];
    }

    protected function applyItemExtra(CatalogEntryInterface $item, array $extra): void
    {
        assert($item instanceof SchoolEventType);

        $item->setColor(SchoolEventTypeColor::normalize($extra['color'] ?? null));
    }
}
