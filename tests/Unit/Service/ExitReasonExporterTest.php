<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Repository\ExitReasonRepository;
use App\Service\ExitReasonExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

class ExitReasonExporterTest extends TestCase
{
    private ExitReasonRepository&Stub $repo;
    private ExitReasonExporter $exporter;
    private EducationalCentre $centre;

    protected function setUp(): void
    {
        $this->repo     = $this->createStub(ExitReasonRepository::class);
        $this->exporter = new ExitReasonExporter($this->repo, new MockClock());
        $this->centre   = (new EducationalCentre())->setName('IES Prueba');
        $this->setId($this->centre);
    }

    public function testExportContainsCentreNameAndEmptyReasons(): void
    {
        $this->repo->method('findByCentreOrdered')->willReturn([]);

        $data = $this->exporter->export($this->centre);

        self::assertSame('IES Prueba', $data['centre']);
        self::assertSame([], $data['reasons']);
    }

    public function testExportIncludesNameAndActiveFlagInOrder(): void
    {
        $first  = $this->makeReason('Servicio', true);
        $second = $this->makeReason('Fuente', false);
        $this->repo->method('findByCentreOrdered')->willReturn([$first, $second]);

        $data = $this->exporter->export($this->centre);

        self::assertSame('Servicio', $data['reasons'][0]['name']);
        self::assertTrue($data['reasons'][0]['active']);
        self::assertSame('Fuente', $data['reasons'][1]['name']);
        self::assertFalse($data['reasons'][1]['active']);
    }

    private function makeReason(string $name, bool $active): ExitReason
    {
        $reason = (new ExitReason())->setEducationalCentre($this->centre)->setName($name)->setActive($active);
        $this->setId($reason);

        return $reason;
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
