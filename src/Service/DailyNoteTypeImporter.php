<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Repository\DailyNoteRepository;
use App\Repository\DailyNoteTypeRepository;
use App\Service\Catalog\AbstractCatalogImporter;
use Doctrine\ORM\EntityManagerInterface;

class DailyNoteTypeImporter extends AbstractCatalogImporter
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly DailyNoteTypeRepository $types,
        private readonly DailyNoteRepository $notes,
    ) {
        parent::__construct($em);
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsKey(): string
    {
        return 'types';
    }

    protected function findExistingItemsForCentre(EducationalCentre $centre): iterable
    {
        return $this->types->findByCentreOrdered($centre);
    }

    protected function countExistingItemsForCentre(EducationalCentre $centre): int
    {
        return $this->types->countByCentre($centre);
    }

    protected function createItem(
        EducationalCentre $centre,
        ?CatalogCategoryInterface $category,
        string $name,
        int $position,
    ): CatalogEntryInterface {
        return (new DailyNoteType())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    protected function applyItemExtra(CatalogEntryInterface $item, array $itemData): void
    {
        assert($item instanceof DailyNoteType);

        $occurrences = $itemData['occurrences_for_report'] ?? 0;
        $item->setOccurrencesForReport(is_numeric($occurrences) ? (int) $occurrences : 0);

        $expiryDays = $itemData['expiry_days'] ?? 0;
        $item->setExpiryDays(is_numeric($expiryDays) ? (int) $expiryDays : 0);
    }

    protected function canRemove(object $entity): bool
    {
        assert($entity instanceof DailyNoteType);

        return $this->notes->countByType($entity) === 0;
    }
}
