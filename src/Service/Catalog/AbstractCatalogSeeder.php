<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Catalog\CatalogCategoryInterface;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Siembra el catálogo por defecto (config/*.yaml) de un centro recién creado, agrupado por
 * categorías o plano según el catálogo.
 */
abstract class AbstractCatalogSeeder
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        protected readonly string $projectDir,
    ) {}

    /** Nombre del fichero bajo config/, p. ej. 'incident_behaviors.yaml'. */
    abstract protected function configFile(): string;

    abstract protected function hasCategories(): bool;

    /** Clave de la lista de entradas, tanto a nivel raíz (plano) como dentro de cada categoría. */
    abstract protected function itemsKey(): string;

    protected function createCategory(EducationalCentre $centre, string $name, int $position): CatalogCategoryInterface
    {
        throw new \LogicException('Este catálogo no tiene categorías.');
    }

    /** @param array<mixed> $catData */
    protected function applyCategoryExtra(CatalogCategoryInterface $category, array $catData): void
    {
    }

    abstract protected function createItem(
        EducationalCentre $centre,
        ?CatalogCategoryInterface $category,
        string $name,
        int $position,
    ): CatalogEntryInterface;

    /**
     * Extrae [nombre, datos extra] de una entrada bruta del YAML, o null si no es válida. Por
     * defecto admite listas planas de strings; los catálogos con más datos por entrada (p. ej.
     * sanction_measures, con has_date_range) lo sobrescriben.
     *
     * @return array{0: string, 1: array<mixed>}|null
     */
    protected function parseItem(mixed $raw): ?array
    {
        return is_string($raw) ? [$raw, []] : null;
    }

    /** @param array<mixed> $extra */
    protected function applyItemExtra(CatalogEntryInterface $item, array $extra): void
    {
    }

    public function seedForCentre(EducationalCentre $centre): void
    {
        $config = Yaml::parseFile($this->projectDir . '/config/' . $this->configFile());
        if (!is_array($config)) {
            return;
        }

        if ($this->hasCategories()) {
            $this->seedCategorized($config, $centre);

            return;
        }

        $this->seedItems($config, $centre, category: null);
    }

    /** @param array<mixed> $config */
    private function seedCategorized(array $config, EducationalCentre $centre): void
    {
        $rawCategories = $config['categories'] ?? [];
        if (!is_array($rawCategories)) {
            return;
        }

        foreach ($rawCategories as $catPosition => $catData) {
            if (!is_array($catData) || !is_int($catPosition)) {
                continue;
            }

            $catName = is_string($catData['name'] ?? null) ? $catData['name'] : '';
            if ($catName === '') {
                continue;
            }

            $category = $this->createCategory($centre, $catName, $catPosition);
            $this->applyCategoryExtra($category, $catData);
            $this->em->persist($category);

            $this->seedItems($catData, $centre, $category);
        }
    }

    /** @param array<mixed> $config */
    private function seedItems(array $config, EducationalCentre $centre, ?CatalogCategoryInterface $category): void
    {
        $rawItems = $config[$this->itemsKey()] ?? [];
        if (!is_array($rawItems)) {
            return;
        }

        foreach ($rawItems as $itemPosition => $rawItem) {
            if (!is_int($itemPosition)) {
                continue;
            }

            $parsed = $this->parseItem($rawItem);
            if ($parsed === null || $parsed[0] === '') {
                continue;
            }
            [$name, $extra] = $parsed;

            $item = $this->createItem($centre, $category, $name, $itemPosition);
            $this->applyItemExtra($item, $extra);
            $item->setActive(true);

            $this->em->persist($item);
        }
    }
}
