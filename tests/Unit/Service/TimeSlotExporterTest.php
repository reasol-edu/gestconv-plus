<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\TimeSlot;
use App\Repository\TimeSlotRepository;
use App\Service\TimeSlotExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class TimeSlotExporterTest extends TestCase
{
    private TimeSlotRepository&Stub $repo;
    private TimeSlotExporter $exporter;
    private AcademicYear $year;

    protected function setUp(): void
    {
        $this->repo     = $this->createStub(TimeSlotRepository::class);
        $this->exporter = new TimeSlotExporter($this->repo);

        $centre     = (new EducationalCentre())->setName('IES Prueba');
        $this->year = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->setId($this->year);
    }

    public function testExportContainsAcademicYearName(): void
    {
        $this->repo->method('findByAcademicYearOrdered')->willReturn([]);

        $data = $this->exporter->export($this->year);

        self::assertSame('2025-2026', $data['academic_year']);
    }

    public function testExportContainsExportedAtTimestamp(): void
    {
        $this->repo->method('findByAcademicYearOrdered')->willReturn([]);

        $data = $this->exporter->export($this->year);

        self::assertArrayHasKey('exported_at', $data);
        self::assertNotEmpty($data['exported_at']);
    }

    public function testExportReturnsEmptyTimeSlotsWhenNoneExist(): void
    {
        $this->repo->method('findByAcademicYearOrdered')->willReturn([]);

        $data = $this->exporter->export($this->year);

        self::assertSame([], $data['time_slots']);
    }

    public function testExportIncludesTimeSlotFields(): void
    {
        $slot = $this->makeSlot('1ª hora', 0, '08:00', '08:55');
        $this->repo->method('findByAcademicYearOrdered')->willReturn([$slot]);

        $data = $this->exporter->export($this->year);

        $row = $data['time_slots'][0];
        self::assertSame('1ª hora', $row['name']);
        self::assertSame(0, $row['day_of_week']);
        self::assertSame('08:00', $row['start_time']);
        self::assertSame('08:55', $row['end_time']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
