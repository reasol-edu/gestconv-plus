<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Service\Catalog\AbstractCatalogSeeder;

final class DailyNoteTypeSeeder extends AbstractCatalogSeeder
{
    protected function configFile(): string
    {
        return 'daily_note_types.yaml';
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
        return (new DailyNoteType())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    protected function parseItem(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $name        = is_string($raw['name'] ?? null) ? $raw['name'] : '';
        $occurrences = $raw['occurrences_for_report'] ?? 0;
        $expiryDays  = $raw['expiry_days'] ?? 0;

        return [$name, [
            'occurrences_for_report' => is_numeric($occurrences) ? (int) $occurrences : 0,
            'expiry_days'            => is_numeric($expiryDays) ? (int) $expiryDays : 0,
        ]];
    }

    protected function applyItemExtra(CatalogEntryInterface $item, array $extra): void
    {
        assert($item instanceof DailyNoteType);

        $occurrences = $extra['occurrences_for_report'] ?? 0;
        $item->setOccurrencesForReport(is_numeric($occurrences) ? (int) $occurrences : 0);

        $expiryDays = $extra['expiry_days'] ?? 0;
        $item->setExpiryDays(is_numeric($expiryDays) ? (int) $expiryDays : 0);
    }
}
