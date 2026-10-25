<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Entity\SchoolEvent;
use App\Entity\TimeSlot;
use App\Service\BoardTodayBuilder;
use App\Tests\Integration\RepositoryTestCase;

class BoardTodayBuilderTest extends RepositoryTestCase
{
    private BoardTodayBuilder $builder;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BoardTodayBuilder $builder */
        $builder       = self::getContainer()->get(BoardTodayBuilder::class);
        $this->builder = $builder;

        $centre     = (new EducationalCentre())->setCode('43222222')->setName('IES Board')->setCity('Sevilla');
        $this->year = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $this->year);
    }

    public function testBuildIncludesEventsForTheDay(): void
    {
        $this->makeEvent('Claustro', new \DateTimeImmutable('2026-03-10'));

        $report = $this->builder->build($this->year, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(1, $report->events);
        self::assertSame('Claustro', $report->events[0]->getName());
    }

    public function testBuildIncludesEventsEvenOnNonWorkingDay(): void
    {
        $holiday = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-19'))->setDescription('Día del centro')->setAcademicYear($this->year);
        $this->persist($holiday);
        $this->makeEvent('Jornada de puertas abiertas', new \DateTimeImmutable('2026-03-19'));

        $report = $this->builder->build($this->year, new \DateTimeImmutable('2026-03-19'));

        // A diferencia del resto de la pantalla "Hoy" (vacía en día no lectivo),
        // los eventos siguen mostrándose.
        self::assertSame('Día del centro', $report->nonWorkingDayLabel);
        self::assertCount(0, $report->timeSlots);
        self::assertCount(1, $report->events);
        self::assertSame('Jornada de puertas abiertas', $report->events[0]->getName());
    }

    public function testBuildPlacesAnEventInsideTheSlotItOverlapsAndKeepsTheRestApart(): void
    {
        // 2026-03-10 es martes -> dayOfWeek 1
        $slot = (new TimeSlot())->setName('2ª hora')->setDayOfWeek(1)
            ->setStartTime(new \DateTimeImmutable('09:00'))->setEndTime(new \DateTimeImmutable('09:55'))
            ->setAcademicYear($this->year);
        $this->persist($slot);
        $this->makeEvent('Charla', new \DateTimeImmutable('2026-03-10')); // 09:00–10:00
        $late = $this->makeEvent('Claustro', new \DateTimeImmutable('2026-03-10'));
        $late->setStartTime(new \DateTimeImmutable('17:00'))->setEndTime(new \DateTimeImmutable('18:00'));
        $this->flush();

        $report = $this->builder->build($this->year, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(1, $report->timeSlots[0]->events);
        self::assertSame('Charla', $report->timeSlots[0]->events[0]->getName());
        self::assertCount(1, $report->eventsOutsideSlots());
        self::assertSame('Claustro', $report->eventsOutsideSlots()[0]->getName());
    }

    public function testBuildEventsListIsEmptyWhenNoneRegistered(): void
    {
        $report = $this->builder->build($this->year, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(0, $report->events);
    }

    private function makeEvent(string $name, \DateTimeImmutable $date): SchoolEvent
    {
        $event = (new SchoolEvent())
            ->setAcademicYear($this->year)
            ->setDate($date)
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName($name)
            ->setGeneral(true);
        $this->persist($event);

        return $event;
    }
}
