<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importación desde array de un catálogo administrable (IncidentBehavior, LocationOption,
 * SanctionMeasure, CommunicationMethod), agrupado por categorías o plano según el catálogo.
 *
 * El emparejamiento con lo ya existente se hace por nombre en minúsculas: si coincide se
 * actualiza, si no se crea al final de la posición actual.
 */
abstract class AbstractCatalogImporter
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
    ) {}

    abstract protected function hasCategories(): bool;

    /** Clave de la lista de entradas, tanto en $data como en las estadísticas devueltas. */
    abstract protected function itemsKey(): string;

    abstract protected function createItem(
        EducationalCentre $centre,
        ?CatalogCategoryInterface $category,
        string $name,
        int $position,
    ): CatalogEntryInterface;

    /** @param array<mixed> $itemData */
    protected function applyItemExtra(CatalogEntryInterface $item, array $itemData): void
    {
    }

    /** Si al reemplazar el catálogo una entidad no debe borrarse (p. ej. porque está en uso). */
    protected function canRemove(object $entity): bool
    {
        return true;
    }

    /** @return iterable<CatalogCategoryInterface> */
    protected function findExistingCategories(EducationalCentre $centre): iterable
    {
        throw new \LogicException('Este catálogo no tiene categorías.');
    }

    protected function countExistingCategories(EducationalCentre $centre): int
    {
        throw new \LogicException('Este catálogo no tiene categorías.');
    }

    protected function createCategory(EducationalCentre $centre, string $name, int $position): CatalogCategoryInterface
    {
        throw new \LogicException('Este catálogo no tiene categorías.');
    }

    /** @param array<mixed> $catData */
    protected function applyCategoryExtra(CatalogCategoryInterface $category, array $catData): void
    {
    }

    /** @return iterable<CatalogEntryInterface> */
    protected function findExistingItemsForCategory(CatalogCategoryInterface $category): iterable
    {
        throw new \LogicException('Este catálogo no tiene categorías.');
    }

    protected function countExistingItemsForCategory(CatalogCategoryInterface $category): int
    {
        throw new \LogicException('Este catálogo no tiene categorías.');
    }

    /** @return iterable<CatalogEntryInterface> */
    protected function findExistingItemsForCentre(EducationalCentre $centre): iterable
    {
        throw new \LogicException('Este catálogo requiere categorías.');
    }

    protected function countExistingItemsForCentre(EducationalCentre $centre): int
    {
        throw new \LogicException('Este catálogo requiere categorías.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public function import(array $data, EducationalCentre $centre, bool $replaceExisting = false): array
    {
        if ($replaceExisting) {
            $this->deleteExisting($centre);
        }

        return $this->hasCategories()
            ? $this->importCategorized($data, $centre)
            : $this->importFlat($data, $centre);
    }

    private function deleteExisting(EducationalCentre $centre): void
    {
        $entities = $this->hasCategories()
            ? $this->findExistingCategories($centre)
            : $this->findExistingItemsForCentre($centre);

        foreach ($entities as $entity) {
            if (!$this->canRemove($entity)) {
                continue;
            }
            $this->em->remove($entity);
        }

        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    private function importCategorized(array $data, EducationalCentre $centre): array
    {
        $itemsKey = $this->itemsKey();
        $stats    = ['categories' => 0, $itemsKey => 0];

        $existingCategories   = [];
        $nextCategoryPosition = $this->countExistingCategories($centre);
        foreach ($this->findExistingCategories($centre) as $existing) {
            $existingCategories[mb_strtolower($existing->getName())] = $existing;
        }

        foreach ((array) ($data['categories'] ?? []) as $catData) {
            if (!is_array($catData)) {
                continue;
            }
            $catName = $this->cleanName($catData['name'] ?? null);
            if ($catName === '') {
                continue;
            }

            $category = $existingCategories[mb_strtolower($catName)] ?? null;
            if ($category === null) {
                $category = $this->createCategory($centre, $catName, $nextCategoryPosition++);
                $this->em->persist($category);
                $existingCategories[mb_strtolower($catName)] = $category;
                $stats['categories']++;
            }

            $this->applyCategoryExtra($category, $catData);

            $existingItems    = [];
            $nextItemPosition = $this->countExistingItemsForCategory($category);
            foreach ($this->findExistingItemsForCategory($category) as $existing) {
                $existingItems[mb_strtolower($existing->getName())] = $existing;
            }

            foreach ((array) ($catData[$itemsKey] ?? []) as $itemData) {
                if (!is_array($itemData)) {
                    continue;
                }
                $itemName = $this->cleanName($itemData['name'] ?? null);
                if ($itemName === '') {
                    continue;
                }

                $item = $existingItems[mb_strtolower($itemName)] ?? null;
                if ($item === null) {
                    $item = $this->createItem($centre, $category, $itemName, $nextItemPosition++);
                    $this->em->persist($item);
                    $existingItems[mb_strtolower($itemName)] = $item;
                    $stats[$itemsKey]++;
                }

                $this->applyItemExtra($item, $itemData);
                $item->setActive((bool) ($itemData['active'] ?? true));
            }
        }

        $this->em->flush();

        return $stats;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    private function importFlat(array $data, EducationalCentre $centre): array
    {
        $itemsKey = $this->itemsKey();
        $stats    = [$itemsKey => 0];

        $existingItems = [];
        $nextPosition  = $this->countExistingItemsForCentre($centre);
        foreach ($this->findExistingItemsForCentre($centre) as $existing) {
            $existingItems[mb_strtolower($existing->getName())] = $existing;
        }

        foreach ((array) ($data[$itemsKey] ?? []) as $itemData) {
            if (!is_array($itemData)) {
                continue;
            }
            $name = $this->cleanName($itemData['name'] ?? null);
            if ($name === '') {
                continue;
            }

            $item = $existingItems[mb_strtolower($name)] ?? null;
            if ($item === null) {
                $item = $this->createItem($centre, null, $name, $nextPosition++);
                $this->em->persist($item);
                $existingItems[mb_strtolower($name)] = $item;
                $stats[$itemsKey]++;
            }

            $this->applyItemExtra($item, $itemData);
            $item->setActive((bool) ($itemData['active'] ?? true));
        }

        $this->em->flush();

        return $stats;
    }

    private function cleanName(mixed $raw): string
    {
        return is_string($raw) ? trim($raw) : '';
    }
}
