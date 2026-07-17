<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Service\GuardDutyReportBuilder;
use App\Tests\Integration\RepositoryTestCase;

class GuardDutyReportBuilderTest extends RepositoryTestCase
{
    private GuardDutyReportBuilder $builder;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var GuardDutyReportBuilder $builder */
        $builder       = self::getContainer()->get(GuardDutyReportBuilder::class);
        $this->builder = $builder;

        $centre     = (new EducationalCentre())->setCode('43111111')->setName('IES Test')->setCity('Sevilla');
        $this->year = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $this->year);
    }

    public function testGroupsSlotsByStartAndEndTimeAcrossDays(): void
    {
        $ana  = (new Teacher(new PersonName('Ana', 'Pérez')))->setUsername('ana.gd.' . uniqid('', false));
        $luis = (new Teacher(new PersonName('Luis', 'García')))->setUsername('luis.gd.' . uniqid('', false));
        $this->persist($ana, $luis);

        $monday = $this->makeSlot('1ª hora', 0, '08:00', '08:55');
        $monday->addGuard($ana);

        $tuesday = $this->makeSlot('1ª hora', 1, '08:00', '08:55');
        $tuesday->addGuard($luis);

        $this->persist($monday, $tuesday);

        $report = $this->builder->build($this->year);

        self::assertCount(1, $report->rows);
        $row = $report->rows[0];
        self::assertSame('1ª hora', $row->label);
        self::assertCount(1, $row->guardsByDay[0]);
        self::assertSame('Ana', $row->guardsByDay[0][0]->getName()->getFirstName());
        self::assertCount(1, $row->guardsByDay[1]);
        self::assertSame('Luis', $row->guardsByDay[1][0]->getName()->getFirstName());
        self::assertCount(0, $row->guardsByDay[2]);
    }

    public function testRowsAreSortedByStartTime(): void
    {
        $this->persist(
            $this->makeSlot('Recreo', 0, '11:00', '11:30'),
            $this->makeSlot('1ª hora', 0, '08:00', '08:55'),
        );

        $report = $this->builder->build($this->year);

        self::assertCount(2, $report->rows);
        self::assertSame('1ª hora', $report->rows[0]->label);
        self::assertSame('Recreo', $report->rows[1]->label);
    }

    public function testIgnoresSlotsOutsideMondayToFriday(): void
    {
        $this->persist($this->makeSlot('Guardia de fin de semana', 5, '08:00', '08:55'));

        $report = $this->builder->build($this->year);

        self::assertCount(0, $report->rows);
    }

    public function testCombinesDistinctNamesAtTheSameTimeAcrossDays(): void
    {
        $this->persist(
            $this->makeSlot('1ª hora', 0, '08:00', '08:55'),
            $this->makeSlot('Guardia', 1, '08:00', '08:55'),
        );

        $report = $this->builder->build($this->year);

        self::assertCount(1, $report->rows);
        self::assertSame('1ª hora / Guardia', $report->rows[0]->label);
    }

    private function makeSlot(string $name, int $day, string $start, string $end): TimeSlot
    {
        return (new TimeSlot())
            ->setAcademicYear($this->year)
            ->setName($name)
            ->setDayOfWeek($day)
            ->setStartTime(\DateTimeImmutable::createFromFormat('H:i', $start))
            ->setEndTime(\DateTimeImmutable::createFromFormat('H:i', $end));
    }
}
