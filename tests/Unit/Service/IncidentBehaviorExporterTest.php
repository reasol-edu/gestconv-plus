<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Repository\IncidentBehaviorCategoryRepository;
use App\Repository\IncidentBehaviorRepository;
use App\Service\IncidentBehaviorExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class IncidentBehaviorExporterTest extends TestCase
{
    private IncidentBehaviorCategoryRepository&Stub $categoryRepo;
    private IncidentBehaviorRepository&Stub $behaviorRepo;
    private IncidentBehaviorExporter $exporter;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->categoryRepo = $this->createStub(IncidentBehaviorCategoryRepository::class);
        $this->behaviorRepo = $this->createStub(IncidentBehaviorRepository::class);

        $this->exporter = new IncidentBehaviorExporter($this->categoryRepo, $this->behaviorRepo);

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

    public function testExportIncludesCategoryWithSeriousFlag(): void
    {
        $category = $this->makeCategory('Faltas graves', true);
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->behaviorRepo->method('findByCategoryOrdered')->willReturn([]);

        $data = $this->exporter->export($this->centre);

        $cat = $data['categories'][0];
        self::assertSame('Faltas graves', $cat['name']);
        self::assertTrue($cat['serious']);
    }

    public function testExportIncludesBehaviorWithActiveFlag(): void
    {
        $category = $this->makeCategory('Faltas leves', false);
        $behavior = $this->makeBehavior('Interrumpir la clase', $category, false);
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->behaviorRepo->method('findByCategoryOrdered')->willReturn([$behavior]);

        $data = $this->exporter->export($this->centre);

        $b = $data['categories'][0]['behaviors'][0];
        self::assertSame('Interrumpir la clase', $b['name']);
        self::assertFalse($b['active']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCategory(string $name, bool $serious): IncidentBehaviorCategory
    {
        $category = (new IncidentBehaviorCategory())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setSerious($serious);
        $this->setId($category);

        return $category;
    }

    private function makeBehavior(string $name, IncidentBehaviorCategory $category, bool $active): IncidentBehavior
    {
        $behavior = (new IncidentBehavior())
            ->setEducationalCentre($this->centre)
            ->setCategory($category)
            ->setName($name)
            ->setActive($active);
        $this->setId($behavior);

        return $behavior;
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
