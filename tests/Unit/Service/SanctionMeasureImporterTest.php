<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Repository\SanctionMeasureCategoryRepository;
use App\Repository\SanctionMeasureRepository;
use App\Service\SanctionMeasureImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class SanctionMeasureImporterTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private SanctionMeasureCategoryRepository&Stub $categoryRepo;
    private SanctionMeasureRepository&Stub $measureRepo;
    private SanctionMeasureImporter $importer;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->categoryRepo = $this->createStub(SanctionMeasureCategoryRepository::class);
        $this->measureRepo  = $this->createStub(SanctionMeasureRepository::class);

        $this->importer = new SanctionMeasureImporter($this->em, $this->categoryRepo, $this->measureRepo);

        $this->centre = new EducationalCentre();
        $this->setId($this->centre);
    }

    public function testImportCreatesCategoriesAndMeasures(): void
    {
        $this->setUpEmptyRepositories();

        $stats = $this->importer->import($this->buildData(['Expulsión' => ['Expulsión de 3 días', 'Expulsión definitiva']]), $this->centre);

        self::assertSame(1, $stats['categories']);
        self::assertSame(2, $stats['measures']);
    }

    public function testImportSkipsEmptyCategoryName(): void
    {
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([]);
        $this->categoryRepo->method('countByCentre')->willReturn(0);
        $this->em->expects(self::never())->method('persist');

        $stats = $this->importer->import(['categories' => [['name' => '  ']]], $this->centre);

        self::assertSame(0, $stats['categories']);
    }

    public function testImportSkipsEmptyMeasureName(): void
    {
        $this->setUpEmptyRepositories();

        $stats = $this->importer->import($this->buildData(['Expulsión' => ['']]), $this->centre);

        self::assertSame(0, $stats['measures']);
    }

    public function testImportDoesNotDuplicateExistingCategoryByName(): void
    {
        $category = $this->makeCategory('Expulsión');
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->categoryRepo->method('countByCentre')->willReturn(1);
        $this->measureRepo->method('findByCategoryOrdered')->willReturn([]);
        $this->measureRepo->method('countByCategory')->willReturn(0);

        $stats = $this->importer->import(['categories' => [['name' => 'Expulsión', 'measures' => []]]], $this->centre);

        self::assertSame(0, $stats['categories']);
    }

    public function testImportMatchesCategoryNamesCaseInsensitively(): void
    {
        $category = $this->makeCategory('Expulsión');
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->categoryRepo->method('countByCentre')->willReturn(1);
        $this->measureRepo->method('findByCategoryOrdered')->willReturn([]);
        $this->measureRepo->method('countByCategory')->willReturn(0);

        $stats = $this->importer->import(['categories' => [['name' => 'expulsión', 'measures' => []]]], $this->centre);

        self::assertSame(0, $stats['categories']);
    }

    public function testImportUpdatesHasDateRangeAndActiveFlagsOnExistingMeasure(): void
    {
        $category = $this->makeCategory('Expulsión');
        $measure  = $this->makeMeasure('Expulsión de 3 días', $category, false, true);
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->categoryRepo->method('countByCentre')->willReturn(1);
        $this->measureRepo->method('findByCategoryOrdered')->willReturn([$measure]);
        $this->measureRepo->method('countByCategory')->willReturn(1);

        $this->importer->import(
            ['categories' => [['name' => 'Expulsión', 'measures' => [['name' => 'Expulsión de 3 días', 'has_date_range' => true, 'active' => false]]]]],
            $this->centre,
        );

        self::assertTrue($measure->hasDateRange());
        self::assertFalse($measure->isActive());
    }

    public function testReplaceExistingRemovesCurrentCategoriesBeforeImport(): void
    {
        $existing = $this->makeCategory('Antigua');
        $this->categoryRepo->method('findByCentreOrdered')
            ->willReturnOnConsecutiveCalls([$existing], []);
        $this->categoryRepo->method('countByCentre')->willReturn(0);

        $this->em->expects(self::once())->method('remove')->with($existing);

        $this->importer->import(['categories' => []], $this->centre, true);
    }

    public function testFlushIsCalledExactlyOnceWhenNotReplacing(): void
    {
        $this->setUpEmptyRepositories();
        $this->em->expects(self::once())->method('flush');

        $this->importer->import(['categories' => []], $this->centre);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function setUpEmptyRepositories(): void
    {
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([]);
        $this->categoryRepo->method('countByCentre')->willReturn(0);
        $this->measureRepo->method('findByCategoryOrdered')->willReturn([]);
        $this->measureRepo->method('countByCategory')->willReturn(0);
    }

    /**
     * $structure: categoryName → [measureName, ...]
     *
     * @param array<string, list<string>> $structure
     * @return array<string, mixed>
     */
    private function buildData(array $structure): array
    {
        $cats = [];
        foreach ($structure as $catName => $measures) {
            $measuresData = [];
            foreach ($measures as $measureName) {
                $measuresData[] = ['name' => $measureName, 'has_date_range' => false, 'active' => true];
            }
            $cats[] = ['name' => $catName, 'measures' => $measuresData];
        }

        return ['categories' => $cats];
    }

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
