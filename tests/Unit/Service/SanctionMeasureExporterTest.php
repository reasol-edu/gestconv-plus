<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Repository\SanctionMeasureCategoryRepository;
use App\Repository\SanctionMeasureRepository;
use App\Service\SanctionMeasureExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SanctionMeasureExporterTest extends TestCase
{
    private SanctionMeasureCategoryRepository&Stub $categoryRepo;
    private SanctionMeasureRepository&Stub $measureRepo;
    private SanctionMeasureExporter $exporter;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->categoryRepo = $this->createStub(SanctionMeasureCategoryRepository::class);
        $this->measureRepo  = $this->createStub(SanctionMeasureRepository::class);

        $this->exporter = new SanctionMeasureExporter($this->categoryRepo, $this->measureRepo);

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

    public function testExportIncludesMeasureWithDateRangeAndActiveFlags(): void
    {
        $category = $this->makeCategory('Expulsión');
        $measure  = $this->makeMeasure('Expulsión de 3 días', $category, true, false);
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->measureRepo->method('findByCategoryOrdered')->willReturn([$measure]);

        $data = $this->exporter->export($this->centre);

        $m = $data['categories'][0]['measures'][0];
        self::assertSame('Expulsión de 3 días', $m['name']);
        self::assertTrue($m['has_date_range']);
        self::assertFalse($m['active']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCategory(string $name): SanctionMeasureCategory
    {
        $category = (new SanctionMeasureCategory())
            ->setEducationalCentre($this->centre)
            ->setName($name);
        $this->setId($category);

        return $category;
    }

    private function makeMeasure(string $name, SanctionMeasureCategory $category, bool $hasDateRange, bool $active): SanctionMeasure
    {
        $measure = (new SanctionMeasure())
            ->setEducationalCentre($this->centre)
            ->setCategory($category)
            ->setName($name)
            ->setHasDateRange($hasDateRange)
            ->setActive($active);
        $this->setId($measure);

        return $measure;
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
