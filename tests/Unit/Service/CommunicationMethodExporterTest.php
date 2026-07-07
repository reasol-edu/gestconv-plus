<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CommunicationMethod;
use App\Entity\EducationalCentre;
use App\Repository\CommunicationMethodRepository;
use App\Service\CommunicationMethodExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class CommunicationMethodExporterTest extends TestCase
{
    private CommunicationMethodRepository&Stub $methodRepo;
    private CommunicationMethodExporter $exporter;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->methodRepo = $this->createStub(CommunicationMethodRepository::class);

        $this->exporter = new CommunicationMethodExporter($this->methodRepo);

        $this->centre = (new EducationalCentre())->setName('IES Prueba');
        $this->setId($this->centre);
    }

    public function testExportContainsCentreName(): void
    {
        $this->methodRepo->method('findByCentreOrdered')->willReturn([]);

        $data = $this->exporter->export($this->centre);

        self::assertSame('IES Prueba', $data['centre']);
    }

    public function testExportContainsExportedAtTimestamp(): void
    {
        $this->methodRepo->method('findByCentreOrdered')->willReturn([]);

        $data = $this->exporter->export($this->centre);

        self::assertArrayHasKey('exported_at', $data);
        self::assertNotEmpty($data['exported_at']);
    }

    public function testExportReturnsEmptyMethodsWhenNoneExist(): void
    {
        $this->methodRepo->method('findByCentreOrdered')->willReturn([]);

        $data = $this->exporter->export($this->centre);

        self::assertSame([], $data['methods']);
    }

    public function testExportIncludesMethodNameAndActiveFlag(): void
    {
        $method = $this->makeMethod('Correo electrónico', false);
        $this->methodRepo->method('findByCentreOrdered')->willReturn([$method]);

        $data = $this->exporter->export($this->centre);

        $m = $data['methods'][0];
        self::assertSame('Correo electrónico', $m['name']);
        self::assertFalse($m['active']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
