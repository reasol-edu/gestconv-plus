<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use App\Repository\LocationOptionCategoryRepository;
use App\Repository\LocationOptionRepository;
use App\Service\LocationOptionExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class LocationOptionExporterTest extends TestCase
{
    private LocationOptionCategoryRepository&Stub $categoryRepo;
    private LocationOptionRepository&Stub $optionRepo;
    private LocationOptionExporter $exporter;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->categoryRepo = $this->createStub(LocationOptionCategoryRepository::class);
        $this->optionRepo   = $this->createStub(LocationOptionRepository::class);

        $this->exporter = new LocationOptionExporter($this->categoryRepo, $this->optionRepo);

        $this->centre = (new EducationalCentre())->setName('IES Prueba');
        $this->setId($this->centre);
    }

    public function testExportContainsCentreName(): void
    {
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([]);

        $data = $this->exporter->export($this->centre);

        self::assertSame('IES Prueba', $data['centre']);
    }

    public function testExportContainsExportedAtTimestamp(): void
    {
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([]);

        $data = $this->exporter->export($this->centre);

        self::assertArrayHasKey('exported_at', $data);
        self::assertNotEmpty($data['exported_at']);
    }

    public function testExportReturnsEmptyCategoriesWhenNoneExist(): void
    {
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([]);

        $data = $this->exporter->export($this->centre);

        self::assertSame([], $data['categories']);
    }

    public function testExportIncludesCategoryName(): void
    {
        $category = $this->makeCategory('General');
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->optionRepo->method('findByCategoryOrdered')->willReturn([]);

        $data = $this->exporter->export($this->centre);

        self::assertSame('General', $data['categories'][0]['name']);
    }

    public function testExportIncludesOptionWithActiveFlag(): void
    {
        $category = $this->makeCategory('General');
        $option   = $this->makeOption('En clase', $category, false);
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->optionRepo->method('findByCategoryOrdered')->willReturn([$option]);

        $data = $this->exporter->export($this->centre);

        $o = $data['categories'][0]['options'][0];
        self::assertSame('En clase', $o['name']);
        self::assertFalse($o['active']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCategory(string $name): LocationOptionCategory
    {
        $category = (new LocationOptionCategory())
            ->setEducationalCentre($this->centre)
            ->setName($name);
        $this->setId($category);

        return $category;
    }

    private function makeOption(string $name, LocationOptionCategory $category, bool $active): LocationOption
    {
        $option = (new LocationOption())
            ->setEducationalCentre($this->centre)
            ->setCategory($category)
            ->setName($name)
            ->setActive($active);
        $this->setId($option);

        return $option;
    }

    private function setId(object $entity): void
    {
        $class = new \ReflectionClass($entity);
        while (!$class->hasProperty('id')) {
            $class = $class->getParentClass();
        }
        $class->getProperty('id')->setValue($entity, Uuid::v7());
    }
}
