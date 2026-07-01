<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Entity\IncidentBehaviorCategory;
use App\Repository\IncidentBehaviorCategoryRepository;
use App\Repository\IncidentBehaviorRepository;
use App\Service\IncidentBehaviorImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class IncidentBehaviorImporterTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private IncidentBehaviorCategoryRepository&Stub $categoryRepo;
    private IncidentBehaviorRepository&Stub $behaviorRepo;
    private IncidentBehaviorImporter $importer;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->categoryRepo = $this->createStub(IncidentBehaviorCategoryRepository::class);
        $this->behaviorRepo = $this->createStub(IncidentBehaviorRepository::class);

        $this->importer = new IncidentBehaviorImporter($this->em, $this->categoryRepo, $this->behaviorRepo);

        $this->centre = new EducationalCentre();
        $this->setId($this->centre);
    }

    public function testImportCreatesCategoriesAndBehaviors(): void
    {
        $this->setUpEmptyRepositories();

        $stats = $this->importer->import($this->buildData(['Faltas leves' => ['Llegar tarde', 'No traer material']]), $this->centre);

        self::assertSame(1, $stats['categories']);
        self::assertSame(2, $stats['behaviors']);
    }

    public function testImportSkipsEmptyCategoryName(): void
    {
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([]);
        $this->categoryRepo->method('countByCentre')->willReturn(0);
        $this->em->expects(self::never())->method('persist');

        $stats = $this->importer->import(['categories' => [['name' => '  ']]], $this->centre);

        self::assertSame(0, $stats['categories']);
    }

    public function testImportSkipsEmptyBehaviorName(): void
    {
        $this->setUpEmptyRepositories();

        $stats = $this->importer->import($this->buildData(['Faltas leves' => ['']]), $this->centre);

        self::assertSame(0, $stats['behaviors']);
    }

    public function testImportDoesNotDuplicateExistingCategoryByName(): void
    {
        $category = $this->makeCategory('Faltas leves');
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->categoryRepo->method('countByCentre')->willReturn(1);
        $this->behaviorRepo->method('findByCategoryOrdered')->willReturn([]);
        $this->behaviorRepo->method('countByCategory')->willReturn(0);

        $stats = $this->importer->import(['categories' => [['name' => 'Faltas leves', 'behaviors' => []]]], $this->centre);

        self::assertSame(0, $stats['categories']);
    }

    public function testImportMatchesCategoryNamesCaseInsensitively(): void
    {
        $category = $this->makeCategory('Faltas leves');
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->categoryRepo->method('countByCentre')->willReturn(1);
        $this->behaviorRepo->method('findByCategoryOrdered')->willReturn([]);
        $this->behaviorRepo->method('countByCategory')->willReturn(0);

        $stats = $this->importer->import(['categories' => [['name' => 'faltas leves', 'behaviors' => []]]], $this->centre);

        self::assertSame(0, $stats['categories']);
    }

    public function testImportUpdatesSeriousFlagOnExistingCategory(): void
    {
        $category = $this->makeCategory('Faltas leves', false);
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->categoryRepo->method('countByCentre')->willReturn(1);
        $this->behaviorRepo->method('findByCategoryOrdered')->willReturn([]);
        $this->behaviorRepo->method('countByCategory')->willReturn(0);

        $this->importer->import(['categories' => [['name' => 'Faltas leves', 'serious' => true, 'behaviors' => []]]], $this->centre);

        self::assertTrue($category->isSerious());
    }

    public function testImportUpdatesActiveFlagOnExistingBehavior(): void
    {
        $category = $this->makeCategory('Faltas leves');
        $behavior = $this->makeBehavior('Llegar tarde', $category, true);
        $this->categoryRepo->method('findByCentreOrdered')->willReturn([$category]);
        $this->categoryRepo->method('countByCentre')->willReturn(1);
        $this->behaviorRepo->method('findByCategoryOrdered')->willReturn([$behavior]);
        $this->behaviorRepo->method('countByCategory')->willReturn(1);

        $this->importer->import(
            ['categories' => [['name' => 'Faltas leves', 'behaviors' => [['name' => 'Llegar tarde', 'active' => false]]]]],
            $this->centre,
        );

        self::assertFalse($behavior->isActive());
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
        $this->behaviorRepo->method('findByCategoryOrdered')->willReturn([]);
        $this->behaviorRepo->method('countByCategory')->willReturn(0);
    }

    /**
     * $structure: categoryName → [behaviorName, ...]
     *
     * @param array<string, list<string>> $structure
     * @return array<string, mixed>
     */
    private function buildData(array $structure): array
    {
        $cats = [];
        foreach ($structure as $catName => $behaviors) {
            $behaviorsData = [];
            foreach ($behaviors as $behaviorName) {
                $behaviorsData[] = ['name' => $behaviorName, 'active' => true];
            }
            $cats[] = ['name' => $catName, 'serious' => false, 'behaviors' => $behaviorsData];
        }

        return ['categories' => $cats];
    }

    private function makeCategory(string $name, bool $serious = false): IncidentBehaviorCategory
    {
        $category = (new IncidentBehaviorCategory())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setSerious($serious);
        $this->setId($category);

        return $category;
    }

    private function makeBehavior(string $name, IncidentBehaviorCategory $category, bool $active): \App\Entity\IncidentBehavior
    {
        $behavior = (new \App\Entity\IncidentBehavior())
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
