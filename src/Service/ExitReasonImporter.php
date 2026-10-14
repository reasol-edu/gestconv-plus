<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Repository\ExitReasonRepository;
use App\Repository\StudentExitRepository;
use App\Service\Catalog\AbstractCatalogImporter;
use Doctrine\ORM\EntityManagerInterface;

class ExitReasonImporter extends AbstractCatalogImporter
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly ExitReasonRepository $reasons,
        private readonly StudentExitRepository $exits,
    ) {
        parent::__construct($em);
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsKey(): string
    {
        return 'reasons';
    }

    protected function findExistingItemsForCentre(EducationalCentre $centre): iterable
    {
        return $this->reasons->findByCentreOrdered($centre);
    }

    protected function countExistingItemsForCentre(EducationalCentre $centre): int
    {
        return $this->reasons->countByCentre($centre);
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

    protected function canRemove(object $entity): bool
    {
        assert($entity instanceof ExitReason);

        return $this->exits->countByReason($entity) === 0;
    }
}
