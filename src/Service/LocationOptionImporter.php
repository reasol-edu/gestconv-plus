<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use App\Repository\LocationOptionCategoryRepository;
use App\Repository\LocationOptionRepository;
use App\Service\Catalog\AbstractCatalogImporter;
use Doctrine\ORM\EntityManagerInterface;

class LocationOptionImporter extends AbstractCatalogImporter
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly LocationOptionCategoryRepository $categories,
        private readonly LocationOptionRepository $options,
    ) {
        parent::__construct($em);
    }

    protected function hasCategories(): bool
    {
        return true;
    }

    protected function itemsKey(): string
    {
        return 'options';
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
        return (new LocationOptionCategory())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    protected function findExistingItemsForCategory(CatalogCategoryInterface $category): iterable
    {
        assert($category instanceof LocationOptionCategory);

        return $this->options->findByCategoryOrdered($category);
    }

    protected function countExistingItemsForCategory(CatalogCategoryInterface $category): int
    {
        assert($category instanceof LocationOptionCategory);

        return $this->options->countByCategory($category);
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
