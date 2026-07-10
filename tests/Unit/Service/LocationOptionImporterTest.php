<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use App\Repository\LocationOptionCategoryRepository;
use App\Repository\LocationOptionRepository;
use App\Service\LocationOptionImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class LocationOptionImporterTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private LocationOptionCategoryRepository&Stub $categoryRepo;
    private LocationOptionRepository&Stub $optionRepo;
    private LocationOptionImporter $importer;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->categoryRepo = $this->createStub(LocationOptionCategoryRepository::class);
        $this->optionRepo   = $this->createStub(LocationOptionRepository::class);

        $this->importer = new LocationOptionImporter($this->em, $this->categoryRepo, $this->optionRepo);

        $this->centre = new EducationalCentre();
        $this->setId($this->centre);
    }

    public function testImportCreatesCategoriesAndOptions(): void
    {
        $this->setUpEmptyRepositories();

        $stats = $this->importer->import($this->buildData(['General' => ['En clase', 'Recreo']]), $this->centre);

        self::assertSame(1, $stats['categories']);
        self::assertSame(2, $stats['options']);
    }

    public function testImportSkipsEmptyCategoryName(): void
    {
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([]);
        $this->categoryRepo->method('countByCentre')->willReturn(0);
        $this->em->expects(self::never())->method('persist');

        $stats = $this->importer->import(['categories' => [['name' => '  ']]], $this->centre);

        self::assertSame(0, $stats['categories']);
    }

    public function testImportSkipsEmptyOptionName(): void
    {
        $this->setUpEmptyRepositories();

        $stats = $this->importer->import($this->buildData(['General' => ['']]), $this->centre);

        self::assertSame(0, $stats['options']);
    }

    public function testImportDoesNotDuplicateExistingCategoryByName(): void
    {
        $category = $this->makeCategory('General');
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->categoryRepo->method('countByCentre')->willReturn(1);
        $this->optionRepo->method('findByCategoryOrdered')->willReturn([]);
        $this->optionRepo->method('countByCategory')->willReturn(0);

        $stats = $this->importer->import(['categories' => [['name' => 'General', 'options' => []]]], $this->centre);

        self::assertSame(0, $stats['categories']);
    }

    public function testImportMatchesCategoryNamesCaseInsensitively(): void
    {
        $category = $this->makeCategory('General');
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->categoryRepo->method('countByCentre')->willReturn(1);
        $this->optionRepo->method('findByCategoryOrdered')->willReturn([]);
        $this->optionRepo->method('countByCategory')->willReturn(0);

        $stats = $this->importer->import(['categories' => [['name' => 'general', 'options' => []]]], $this->centre);

        self::assertSame(0, $stats['categories']);
    }

    public function testImportUpdatesActiveFlagOnExistingOption(): void
    {
        $category = $this->makeCategory('General');
        $option   = $this->makeOption('En clase', $category, true);
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->categoryRepo->method('countByCentre')->willReturn(1);
        $this->optionRepo->method('findByCategoryOrdered')->willReturn([$option]);
        $this->optionRepo->method('countByCategory')->willReturn(1);

        $this->importer->import(
            ['categories' => [['name' => 'General', 'options' => [['name' => 'En clase', 'active' => false]]]]],
            $this->centre,
        );

        self::assertFalse($option->isActive());
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
        $this->optionRepo->method('findByCategoryOrdered')->willReturn([]);
        $this->optionRepo->method('countByCategory')->willReturn(0);
    }

    /**
     * $structure: categoryName → [optionName, ...]
     *
     * @param array<string, list<string>> $structure
     * @return array<string, mixed>
     */
    private function buildData(array $structure): array
    {
        $cats = [];
        foreach ($structure as $catName => $options) {
            $optionsData = [];
            foreach ($options as $optionName) {
                $optionsData[] = ['name' => $optionName, 'active' => true];
            }
            $cats[] = ['name' => $catName, 'options' => $optionsData];
        }

        return ['categories' => $cats];
    }

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
