<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Service\Catalog\AbstractCatalogSeeder;

final class ExitReasonSeeder extends AbstractCatalogSeeder
{
    protected function configFile(): string
    {
        return 'exit_reasons.yaml';
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsKey(): string
    {
        return 'reasons';
    }

    protected function createItem(
        EducationalCentre $centre,
        ?CatalogCategoryInterface $category,
        string $name,
        int $position,
    ): CatalogEntryInterface {
        return (new ExitReason())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }
}
