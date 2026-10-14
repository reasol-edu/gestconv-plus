<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Repository\ExitReasonRepository;
use App\Repository\StudentExitRepository;
use App\Service\ExitReasonImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class ExitReasonImporterTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ExitReasonRepository&Stub $repo;
    private StudentExitRepository&Stub $exits;
    private ExitReasonImporter $importer;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->em    = $this->createMock(EntityManagerInterface::class);
        $this->repo  = $this->createStub(ExitReasonRepository::class);
        $this->exits = $this->createStub(StudentExitRepository::class);

        $this->importer = new ExitReasonImporter($this->em, $this->repo, $this->exits);

        $this->centre = new EducationalCentre();
        $this->setId($this->centre);
    }

    public function testImportCreatesReasons(): void
    {
        $this->repo->method('findByCentreOrdered')->willReturn([]);
        $this->repo->method('countByCentre')->willReturn(0);

        $stats = $this->importer->import(['reasons' => [['name' => 'Servicio'], ['name' => 'Fuente']]], $this->centre);

        self::assertSame(2, $stats['reasons']);
    }

    public function testImportSkipsEmptyNameAndExistingReasonsIgnoringCase(): void
    {
        $existing = (new ExitReason())->setEducationalCentre($this->centre)->setName('Servicio');
        $this->setId($existing);
        $this->repo->method('findByCentreOrdered')->willReturn([$existing]);
        $this->repo->method('countByCentre')->willReturn(1);

        $stats = $this->importer->import(['reasons' => [['name' => '  '], ['name' => 'SERVICIO']]], $this->centre);

        self::assertSame(0, $stats['reasons']);
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
