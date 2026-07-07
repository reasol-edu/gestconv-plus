<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CommunicationMethod;
use App\Entity\EducationalCentre;
use App\Repository\CommunicationMethodRepository;
use App\Repository\CommunicationRepository;
use App\Service\CommunicationMethodImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class CommunicationMethodImporterTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private CommunicationMethodRepository&Stub $methodRepo;
    private CommunicationRepository&Stub $communicationRepo;
    private CommunicationMethodImporter $importer;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->methodRepo        = $this->createStub(CommunicationMethodRepository::class);
        $this->communicationRepo = $this->createStub(CommunicationRepository::class);

        $this->importer = new CommunicationMethodImporter($this->em, $this->methodRepo, $this->communicationRepo);

        $this->centre = new EducationalCentre();
        $this->setId($this->centre);
    }

    public function testImportCreatesMethods(): void
    {
        $this->setUpEmptyRepository();

        $stats = $this->importer->import($this->buildData(['Correo electrónico', 'Teléfono']), $this->centre);

        self::assertSame(2, $stats['methods']);
    }

    public function testImportSkipsEmptyName(): void
    {
        $this->setUpEmptyRepository();

        $stats = $this->importer->import(['methods' => [['name' => '  ']]], $this->centre);

        self::assertSame(0, $stats['methods']);
    }

    public function testImportDoesNotDuplicateExistingMethodByName(): void
    {
        $method = $this->makeMethod('Correo electrónico', true);
        $this->methodRepo->method('findByCentreOrdered')->willReturn([$method]);
        $this->methodRepo->method('countByCentre')->willReturn(1);

        $stats = $this->importer->import(['methods' => [['name' => 'Correo electrónico', 'active' => true]]], $this->centre);

        self::assertSame(0, $stats['methods']);
    }

    public function testImportMatchesNamesCaseInsensitively(): void
    {
        $method = $this->makeMethod('Correo electrónico', true);
        $this->methodRepo->method('findByCentreOrdered')->willReturn([$method]);
        $this->methodRepo->method('countByCentre')->willReturn(1);

        $stats = $this->importer->import(['methods' => [['name' => 'correo electrónico', 'active' => true]]], $this->centre);

        self::assertSame(0, $stats['methods']);
    }

    public function testImportUpdatesActiveFlagOnExistingMethod(): void
    {
        $method = $this->makeMethod('Correo electrónico', true);
        $this->methodRepo->method('findByCentreOrdered')->willReturn([$method]);
        $this->methodRepo->method('countByCentre')->willReturn(1);

        $this->importer->import(['methods' => [['name' => 'Correo electrónico', 'active' => false]]], $this->centre);

        self::assertFalse($method->isActive());
    }

    public function testReplaceExistingRemovesCurrentMethodsBeforeImport(): void
    {
        $existing = $this->makeMethod('Antiguo', true);
        $this->methodRepo->method('findByCentreOrdered')
            ->willReturnOnConsecutiveCalls([$existing], []);
        $this->methodRepo->method('countByCentre')->willReturn(0);
        $this->communicationRepo->method('countByMethod')->willReturn(0);

        $this->em->expects(self::once())->method('remove')->with($existing);

        $this->importer->import(['methods' => []], $this->centre, true);
    }

    public function testReplaceExistingKeepsMethodsThatAreInUse(): void
    {
        $inUse = $this->makeMethod('En uso', true);
        $this->methodRepo->method('findByCentreOrdered')
            ->willReturnOnConsecutiveCalls([$inUse], [$inUse]);
        $this->methodRepo->method('countByCentre')->willReturn(1);
        $this->communicationRepo->method('countByMethod')->willReturn(3);

        $this->em->expects(self::never())->method('remove');

        $this->importer->import(['methods' => []], $this->centre, true);
    }

    public function testFlushIsCalledExactlyOnceWhenNotReplacing(): void
    {
        $this->setUpEmptyRepository();
        $this->em->expects(self::once())->method('flush');

        $this->importer->import(['methods' => []], $this->centre);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function setUpEmptyRepository(): void
    {
        $this->methodRepo->method('findByCentreOrdered')->willReturn([]);
        $this->methodRepo->method('countByCentre')->willReturn(0);
    }

    /**
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private function buildData(array $names): array
    {
        $methods = [];
        foreach ($names as $name) {
            $methods[] = ['name' => $name, 'active' => true];
        }

        return ['methods' => $methods];
    }

    private function makeMethod(string $name, bool $active): CommunicationMethod
    {
        $method = (new CommunicationMethod())
            ->setEducationalCentre($this->centre)
            ->setName($name)
            ->setActive($active);
        $this->setId($method);

        return $method;
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
