<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\TimeSlot;
use App\Repository\TimeSlotRepository;
use App\Service\TimeSlotImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
class TimeSlotImporterTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private TimeSlotRepository&Stub $repo;
    private TimeSlotImporter $importer;
    private AcademicYear $year;

    protected function setUp(): void
    {
        $this->em   = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createStub(TimeSlotRepository::class);

        $this->importer = new TimeSlotImporter($this->em, $this->repo);

        $centre     = (new EducationalCentre())->setName('IES Prueba');
        $this->year = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->setId($this->year);
    }

    public function testImportCreatesTimeSlots(): void
    {
        $this->repo->method('findByAcademicYearOrdered')->willReturn([]);

        $stats = $this->importer->import($this->buildData([
            ['name' => '1ª hora', 'day_of_week' => 0, 'start_time' => '08:00', 'end_time' => '08:55'],
            ['name' => 'Recreo', 'day_of_week' => 0, 'start_time' => '11:00', 'end_time' => '11:30'],
        ]), $this->year);

        self::assertSame(2, $stats['time_slots']);
    }

    public function testImportSkipsEmptyName(): void
    {
        $this->repo->method('findByAcademicYearOrdered')->willReturn([]);
        $this->em->expects(self::never())->method('persist');

        $stats = $this->importer->import($this->buildData([
            ['name' => '  ', 'day_of_week' => 0, 'start_time' => '08:00', 'end_time' => '08:55'],
        ]), $this->year);

        self::assertSame(0, $stats['time_slots']);
    }

    public function testImportSkipsInvalidDayOfWeek(): void
    {
        $this->repo->method('findByAcademicYearOrdered')->willReturn([]);

        $stats = $this->importer->import($this->buildData([
            ['name' => 'Fuera de rango', 'day_of_week' => 7, 'start_time' => '08:00', 'end_time' => '08:55'],
        ]), $this->year);

        self::assertSame(0, $stats['time_slots']);
    }

    public function testImportSkipsWhenStartIsNotBeforeEnd(): void
    {
        $this->repo->method('findByAcademicYearOrdered')->willReturn([]);

        $stats = $this->importer->import($this->buildData([
            ['name' => 'Invertido', 'day_of_week' => 0, 'start_time' => '09:00', 'end_time' => '08:00'],
        ]), $this->year);

        self::assertSame(0, $stats['time_slots']);
    }

    public function testImportSkipsUnparsableTime(): void
    {
        $this->repo->method('findByAcademicYearOrdered')->willReturn([]);

        $stats = $this->importer->import($this->buildData([
            ['name' => 'Hora inválida', 'day_of_week' => 0, 'start_time' => 'not-a-time', 'end_time' => '08:55'],
        ]), $this->year);

        self::assertSame(0, $stats['time_slots']);
    }

    public function testImportDoesNotDuplicateExistingSignature(): void
    {
        $existing = $this->makeSlot('1ª hora', 0, '08:00', '08:55');
        $this->repo->method('findByAcademicYearOrdered')->willReturn([$existing]);

        $stats = $this->importer->import($this->buildData([
            ['name' => '1ª hora', 'day_of_week' => 0, 'start_time' => '08:00', 'end_time' => '08:55'],
        ]), $this->year);

        self::assertSame(0, $stats['time_slots']);
    }

    public function testImportSignatureMatchIsCaseInsensitive(): void
    {
        $existing = $this->makeSlot('1ª Hora', 0, '08:00', '08:55');
        $this->repo->method('findByAcademicYearOrdered')->willReturn([$existing]);

        $stats = $this->importer->import($this->buildData([
            ['name' => '1ª hora', 'day_of_week' => 0, 'start_time' => '08:00', 'end_time' => '08:55'],
        ]), $this->year);

        self::assertSame(0, $stats['time_slots']);
    }

    public function testReplaceExistingRemovesCurrentSlotsBeforeImport(): void
    {
        $existing = $this->makeSlot('Antigua', 0, '08:00', '08:55');
        $this->repo->method('findByAcademicYearOrdered')
            ->willReturnOnConsecutiveCalls([$existing], []);

        $this->em->expects(self::once())->method('remove')->with($existing);

        $this->importer->import(['time_slots' => []], $this->year, true);
    }

    public function testFlushIsCalledExactlyOnceWhenNotReplacing(): void
    {
        $this->repo->method('findByAcademicYearOrdered')->willReturn([]);
        $this->em->expects(self::once())->method('flush');

        $this->importer->import(['time_slots' => []], $this->year);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildData(array $rows): array
    {
        return ['time_slots' => $rows];
    }

    private function makeSlot(string $name, int $day, string $start, string $end): TimeSlot
    {
        $slot = (new TimeSlot())
            ->setAcademicYear($this->year)
            ->setName($name)
            ->setDayOfWeek($day)
            ->setStartTime(\DateTimeImmutable::createFromFormat('H:i', $start))
            ->setEndTime(\DateTimeImmutable::createFromFormat('H:i', $end));
        $this->setId($slot);

        return $slot;
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
