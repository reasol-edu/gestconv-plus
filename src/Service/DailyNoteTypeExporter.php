<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Repository\DailyNoteTypeRepository;
use App\Service\Catalog\AbstractCatalogExporter;
use Symfony\Component\Clock\ClockInterface;

class DailyNoteTypeExporter extends AbstractCatalogExporter
{
    public function __construct(
        private readonly DailyNoteTypeRepository $types,
        ClockInterface $clock,
    ) {
        parent::__construct($clock);
    }

    protected function itemsKey(): string
    {
        return 'types';
    }

    protected function hasCategories(): bool
    {
        return false;
    }

    protected function itemsForCentre(EducationalCentre $centre): iterable
    {
        return $this->types->findByCentreOrdered($centre);
    }

    protected function itemExtra(CatalogEntryInterface $item): array
    {
        assert($item instanceof DailyNoteType);

        return [
            'occurrences_for_report' => $item->getOccurrencesForReport(),
            'expiry_days'            => $item->getExpiryDays(),
        ];
    }
}
