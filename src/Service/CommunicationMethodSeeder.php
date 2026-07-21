<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\CommunicationMethod;
use App\Entity\EducationalCentre;
use App\Service\Catalog\AbstractCatalogSeeder;

final class CommunicationMethodSeeder extends AbstractCatalogSeeder
{
    protected function configFile(): string
    {
        return 'communication_methods.yaml';
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsKey(): string
    {
        return 'methods';
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
}
