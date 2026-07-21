<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;

/**
 * Exportación a array de un catálogo administrable (IncidentBehavior, LocationOption,
 * SanctionMeasure, CommunicationMethod), agrupado por categorías o plano según el catálogo.
 */
abstract class AbstractCatalogExporter
{
    /** Clave bajo la que se listan las entradas ('behaviors', 'options', 'measures', 'methods'). */
    abstract protected function itemsKey(): string;

    abstract protected function hasCategories(): bool;

    /** @return iterable<CatalogCategoryInterface> */
    protected function categoriesFor(EducationalCentre $centre): iterable
    {
        throw new \LogicException('Este catálogo no tiene categorías.');
    }

    /** @return iterable<CatalogEntryInterface> */
    protected function itemsForCategory(CatalogCategoryInterface $category): iterable
    {
        throw new \LogicException('Este catálogo no tiene categorías.');
    }

    /** @return iterable<CatalogEntryInterface> */
    protected function itemsForCentre(EducationalCentre $centre): iterable
    {
        throw new \LogicException('Este catálogo requiere categorías.');
    }

    /** @return array<string, mixed> */
    protected function categoryExtra(CatalogCategoryInterface $category): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function itemExtra(CatalogEntryInterface $item): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function export(EducationalCentre $centre): array
    {
        $data = [
            'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'centre'      => $centre->getName(),
        ];

        if ($this->hasCategories()) {
            $data['categories'] = [];
            foreach ($this->categoriesFor($centre) as $category) {
                $categoryData = array_merge(
                    ['name' => $category->getName()],
                    $this->categoryExtra($category),
                );
                $categoryData[$this->itemsKey()] = array_map(
                    fn (CatalogEntryInterface $item): array => $this->exportItem($item),
                    [...$this->itemsForCategory($category)],
                );

                $data['categories'][] = $categoryData;
            }

            return $data;
        }

        $data[$this->itemsKey()] = array_map(
            fn (CatalogEntryInterface $item): array => $this->exportItem($item),
            [...$this->itemsForCentre($centre)],
        );

        return $data;
    }

    /** @return array<string, mixed> */
    private function exportItem(CatalogEntryInterface $item): array
    {
        return array_merge(
            ['name' => $item->getName()],
            $this->itemExtra($item),
            ['active' => $item->isActive()],
        );
    }
}
