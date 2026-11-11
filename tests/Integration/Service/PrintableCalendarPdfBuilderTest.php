<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\PrintableCalendar;
use App\Entity\PrintableCalendarPeriodMode;
use App\Entity\Teacher;
use App\Model\PrintableCalendarMonth;
use App\Service\PrintableCalendarPdfBuilder;
use App\Tests\Integration\RepositoryTestCase;

final class PrintableCalendarPdfBuilderTest extends RepositoryTestCase
{
    private PrintableCalendarPdfBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var PrintableCalendarPdfBuilder $builder */
        $builder       = self::getContainer()->get(PrintableCalendarPdfBuilder::class);
        $this->builder = $builder;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function year(EducationalCentre $centre): AcademicYear
    {
        return (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function calendar(EducationalCentre $centre, AcademicYear $year, Teacher $owner): PrintableCalendar
    {
        return new PrintableCalendar($centre, $year, $owner, 'FCT 2º CFGS');
    }

    /** @return list<string> */
    private function monthAnnotationLabels(iterable $months, int $month): array
    {
        foreach ($months as $m) {
            \assert($m instanceof PrintableCalendarMonth);
            if ($m->month === $month) {
                return array_map(static fn ($a): string => $a->label, $m->annotations);
            }
        }

        return [];
    }

    public function testUniformHoursPeriodComputesTheJourneySummaryWithADifferentLastDay(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Monday 2026-03-02, 8h/day Mon-Fri, 20h total → two full 8h days (Mon, Tue) then a
        // partial 4h day (Wed) — same "N jornadas de H horas" + "y 1 de H2 horas" split as the
        // FCT example that motivated this feature (47 @ 8h + 1 @ 4h).
        $calendar->addPeriod('FCT', '#c7d2fe', true, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-03-02'), null, 20.0, 8.0, 8.0, 8.0, 8.0, 8.0);
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertCount(1, $data->journeySummaries);
        self::assertSame(['2 jornadas de 8 horas', 'y 1 jornada de 4 horas', 'Total: 20 horas'], $data->journeySummaries[0]->hoursLines);
        self::assertEquals(new \DateTimeImmutable('2026-03-02'), $data->journeySummaries[0]->startDate);
        self::assertEquals(new \DateTimeImmutable('2026-03-04'), $data->journeySummaries[0]->endDate);
    }

    public function testTheJourneySummaryIsOrderedByStartDateRegardlessOfThePeriodsOrder(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Dados de alta en orden inverso al cronológico.
        $calendar->addPeriod('Tercera', '#c7d2fe', true, PrintableCalendarPeriodMode::DateRange, new \DateTimeImmutable('2026-05-04'), new \DateTimeImmutable('2026-05-08'), null, null, null, null, null, null);
        $calendar->addPeriod('Primera', '#c7d2fe', true, PrintableCalendarPeriodMode::DateRange, new \DateTimeImmutable('2026-03-02'), new \DateTimeImmutable('2026-03-06'), null, null, null, null, null, null);
        $calendar->addPeriod('Segunda', '#c7d2fe', true, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-04-06'), null, 8.0, 8.0, null, null, null, null);
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertSame(['Primera', 'Segunda', 'Tercera'], array_map(static fn ($l): string => $l->description, $data->journeySummaries));
    }

    public function testACompactJourneySummaryShowsOnlyTheTotalHours(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        $calendar->addPeriod('FCT', '#c7d2fe', true, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-03-02'), null, 20.0, 8.0, 8.0, 8.0, 8.0, 8.0, true, true);
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertSame(['20 horas'], $data->journeySummaries[0]->hoursLines);
        self::assertEquals(new \DateTimeImmutable('2026-03-02'), $data->journeySummaries[0]->startDate);
        self::assertEquals(new \DateTimeImmutable('2026-03-04'), $data->journeySummaries[0]->endDate);
    }

    public function testTheJourneySummaryUsesTheSingularOnlyForExactlyOne(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Lunes 1 h (única jornada → «1 jornada de 1 hora», total «1 hora»); martes, otro periodo, 1,5 h (plural).
        $calendar->addPeriod('Una', '#c7d2fe', true, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-03-02'), null, 1.0, 1.0, null, null, null, null);
        $calendar->addPeriod('Media', '#c7d2fe', true, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-03-09'), null, 1.5, 1.5, null, null, null, null, true, true);
        $calendar->addPeriod('Una compacta', '#c7d2fe', true, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-03-16'), null, 1.0, 1.0, null, null, null, null, true, true);
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertSame(['1 jornada de 1 hora', 'Total: 1 hora'], $data->journeySummaries[0]->hoursLines);
        self::assertSame(['1,5 horas'], $data->journeySummaries[1]->hoursLines);
        self::assertSame(['1 hora'], $data->journeySummaries[2]->hoursLines);
    }

    private function sequenceCalendar(): PrintableCalendar
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Monday 2 March 2026, 8 h a day: UD1 = Mon-Wed (20 h), UD2 = Wed-Thu (10 h), UD3 = Thu-Mon (12 h).
        $sequence = $calendar->addSequence('Unidades didácticas', new \DateTimeImmutable('2026-03-02'), '#bfdbfe', '#fde68a');
        $sequence->setWeekdayHours([1 => 8.0, 2 => 8.0, 3 => 8.0, 4 => 8.0, 5 => 8.0]);
        $sequence->addItem('UD1', 20.0);
        $sequence->addItem('UD2', 10.0);
        $sequence->addItem('UD3', 12.0);
        $this->persist($centre, $year, $teacher, $calendar);

        return $calendar;
    }

    /** @return array<string, ?string> ISO date => background colour of the first month's days */
    private function dayColors(PrintableCalendar $calendar): array
    {
        $colors = [];
        foreach ($this->builder->build($calendar)->months as $month) {
            foreach ($month->weeks as $week) {
                foreach ($week as $day) {
                    if ($day !== null) {
                        $colors[$day->date->format('Y-m-d')] = $day->backgroundColor;
                    }
                }
            }
        }

        return $colors;
    }

    public function testASequenceGetsItsOwnSummaryBoxWithItsItemsAndTotal(): void
    {
        $data = $this->builder->build($this->sequenceCalendar());

        self::assertCount(1, $data->journeySummaries);
        $line = $data->journeySummaries[0];
        self::assertSame('Unidades didácticas', $line->description);
        self::assertSame(
            [['UD1', '2026-03-02', '2026-03-04', '20 horas'], ['UD2', '2026-03-04', '2026-03-05', '10 horas'], ['UD3', '2026-03-05', '2026-03-09', '12 horas']],
            array_map(static fn ($i): array => [$i->description, $i->startDate->format('Y-m-d'), $i->endDate->format('Y-m-d'), $i->hoursLabel], $line->items),
        );
        self::assertSame(['Total: 42 horas'], $line->hoursLines);
        self::assertEquals(new \DateTimeImmutable('2026-03-09'), $data->effectiveEnd);
    }

    public function testEachSequenceHasItsOwnBoxAndPeriodsAroundItKeepTheirOrderInTheirOwn(): void
    {
        $calendar = $this->sequenceCalendar();
        $calendar->addPeriod('Antes', '#c7d2fe', true, PrintableCalendarPeriodMode::DateRange, new \DateTimeImmutable('2026-02-02'), new \DateTimeImmutable('2026-02-06'), null, null, null, null, null, null);
        $calendar->addPeriod('Después', '#c7d2fe', true, PrintableCalendarPeriodMode::DateRange, new \DateTimeImmutable('2026-04-06'), new \DateTimeImmutable('2026-04-10'), null, null, null, null, null, null);
        $second = $calendar->addSequence('Segunda secuencia', new \DateTimeImmutable('2026-05-04'), '#bfdbfe', '#fde68a');
        $second->setWeekdayHours([1 => 8.0]);
        $second->addItem('X', 8.0);
        $this->em->flush();

        $boxes = $this->builder->build($calendar)->journeyBoxes();

        self::assertSame(
            [['Antes'], ['Unidades didácticas'], ['Después'], ['Segunda secuencia']],
            array_map(static fn (array $box): array => array_map(static fn ($l): string => $l->description, $box), $boxes),
        );
    }

    public function testTheItemsAlternateTheTwoColoursAndAMixedDayTakesTheColourOfTheItemWithMostHours(): void
    {
        $colors = $this->dayColors($this->sequenceCalendar());

        self::assertSame('#bfdbfe', $colors['2026-03-02']); // UD1
        self::assertSame('#bfdbfe', $colors['2026-03-03']); // UD1
        self::assertSame('#fde68a', $colors['2026-03-04']); // UD1 4 h / UD2 4 h: the one starting wins
        self::assertSame('#fde68a', $colors['2026-03-05']); // UD2 6 h against UD3 2 h
        self::assertSame('#bfdbfe', $colors['2026-03-06']); // UD3: the third item takes the first colour again
        self::assertSame('#bfdbfe', $colors['2026-03-09']); // UD3
    }

    public function testASequenceHasInicioAndFinLabelsForEachItemInItsColour(): void
    {
        $month = $this->builder->build($this->sequenceCalendar())->months[0];

        self::assertSame(
            [['2', 'Inicio UD1'], ['4', 'Fin UD1'], ['4', 'Inicio UD2'], ['5', 'Fin UD2'], ['5', 'Inicio UD3'], ['9', 'Fin UD3']],
            array_map(static fn ($a): array => [$a->label, $a->description], $month->annotations),
        );
        self::assertSame(['#bfdbfe', '#bfdbfe', '#fde68a', '#fde68a', '#bfdbfe', '#bfdbfe'], array_map(static fn ($a): string => $a->color, $month->annotations));
    }

    public function testASequenceWithTheSummaryOffIsPaintedButNotListed(): void
    {
        $calendar = $this->sequenceCalendar();
        $calendar->getSequences()->first()->setShowJourneySummary(false);

        self::assertSame([], $this->builder->build($calendar)->journeySummaries);
    }

    public function testASequenceItemsDayShowsTheDaysTotalHoursOnlyIfAsked(): void
    {
        $calendar = $this->sequenceCalendar();
        $withHours = [];
        foreach ($this->builder->build($calendar)->months[0]->weeks as $week) {
            foreach ($week as $day) {
                if ($day !== null && $day->hoursLabel !== null) {
                    $withHours[$day->date->format('Y-m-d')] = $day->hoursLabel;
                }
            }
        }
        self::assertSame('8', $withHours['2026-03-04'], 'the mixed day shows the whole day, not only the owner\'s share');

        $calendar->getSequences()->first()->setShowHours(false);
        foreach ($this->builder->build($calendar)->months[0]->weeks as $week) {
            foreach ($week as $day) {
                self::assertNull($day?->hoursLabel);
            }
        }
    }

    public function testADeclaredNonWorkingDayIsSkippedEvenIfItsWeekdayHasHoursAssigned(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Monday 2026-03-02 is declared non-working: the period must skip straight to the next
        // Monday (2026-03-09), the only weekday with hours assigned.
        $holiday = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-02'))->setDescription('Día no lectivo')->setAcademicYear($year);
        $calendar->addPeriod('FCT', '#c7d2fe', false, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-03-02'), null, 8.0, 8.0, null, null, null, null);
        $this->persist($centre, $year, $teacher, $holiday, $calendar);

        $data = $this->builder->build($calendar);

        self::assertEquals(new \DateTimeImmutable('2026-03-09'), $data->effectiveStart);
        self::assertEquals(new \DateTimeImmutable('2026-03-09'), $data->effectiveEnd);
    }

    public function testDayColourPriorityIsPeriodsThenNonWorkingDaysThenIndividualDates(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // A date-range period covers the whole week 2026-03-02 (Mon) … 03-08 (Sun). 03-02 and
        // 03-04 are declared non-working, 03-07/03-08 are the weekend. Priority, lowest to
        // highest: period < weekend / non-working day < individual date (on 03-02 and on the Saturday).
        $holiday  = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-02'))->setDescription('Día no lectivo')->setAcademicYear($year);
        $holiday2 = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-04'))->setDescription('Día no lectivo')->setAcademicYear($year);
        $calendar->addPeriod('Semana', '#c7d2fe', false, PrintableCalendarPeriodMode::DateRange, new \DateTimeImmutable('2026-03-02'), new \DateTimeImmutable('2026-03-08'), null, null, null, null, null, null);
        $calendar->addDate(new \DateTimeImmutable('2026-03-02'), '#a7f3d0', 'Cualquier cosa');
        $calendar->addDate(new \DateTimeImmutable('2026-03-07'), '#fde68a', 'Un sábado');
        $this->persist($centre, $year, $teacher, $holiday, $holiday2, $calendar);

        $data = $this->builder->build($calendar);

        $colors = [];
        foreach ($data->months[0]->weeks as $week) {
            foreach ($week as $day) {
                if ($day !== null) {
                    $colors[$day->date->format('Y-m-d')] = $day->backgroundColor;
                }
            }
        }

        self::assertSame('#a7f3d0', $colors['2026-03-02'], 'an individual date beats a non-working day and the period');
        self::assertSame('#c7d2fe', $colors['2026-03-03'], 'a plain working day keeps the period colour');
        self::assertSame(PrintableCalendar::DEFAULT_NON_WORKING_DAY_COLOR, $colors['2026-03-04'], 'a non-working day beats the period');
        self::assertSame('#fde68a', $colors['2026-03-07'], 'an individual date beats the weekend');
        self::assertSame(PrintableCalendar::DEFAULT_WEEKEND_COLOR, $colors['2026-03-08'], 'the weekend beats the period');
    }

    public function testEachPeriodDecidesWhetherItsDaysShowTheirHours(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Monday 2026-03-02 → Tuesday 2026-03-03 with hours shown; Wednesday 2026-03-04 onwards
        // (a second period, hours hidden) only gets the colour.
        $calendar->addPeriod('Con horas', '#c7d2fe', false, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-03-02'), null, 16.0, 8.0, 8.0, null, null, null, true);
        $calendar->addPeriod('Sin horas', '#fca5a5', false, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-03-04'), null, 8.0, null, null, 8.0, null, null, false);
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        $cells = [];
        foreach ($data->months[0]->weeks as $week) {
            foreach ($week as $day) {
                if ($day !== null) {
                    $cells[$day->date->format('Y-m-d')] = $day;
                }
            }
        }
        self::assertSame('8', $cells['2026-03-02']->hoursLabel);
        self::assertSame('8', $cells['2026-03-03']->hoursLabel);
        self::assertSame('#fca5a5', $cells['2026-03-04']->backgroundColor);
        self::assertNull($cells['2026-03-04']->hoursLabel);
    }

    public function testEveryMonthBoxHasSixWeekRowsEvenWhenTheMonthNeedsFewer(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // February 2026 starts on a Sunday and has 28 days: only 5 calendar weeks — every month
        // box must still render 6, so side-by-side (and stacked) months line up at the same height.
        $calendar->addDate(new \DateTimeImmutable('2026-02-10'), '#a7f3d0', 'Cualquier cosa');
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertCount(1, $data->months);
        self::assertCount(6, $data->months[0]->weeks);
    }

    public function testTheEffectiveRangeIsComputedFromPeriodsAndDatesWhenNotSetExplicitly(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        $calendar->addPeriod('Sesiones', '#fca5a5', false, PrintableCalendarPeriodMode::DateRange, new \DateTimeImmutable('2026-02-10'), new \DateTimeImmutable('2026-02-12'), null, null, null, null, null, null);
        $calendar->addDate(new \DateTimeImmutable('2026-05-20'), '#a7f3d0', 'Graduación');
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertEquals(new \DateTimeImmutable('2026-02-10'), $data->effectiveStart);
        self::assertEquals(new \DateTimeImmutable('2026-05-20'), $data->effectiveEnd);
    }

    public function testAnExplicitDateOverridesTheComputedRange(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        $calendar->setStartDate(new \DateTimeImmutable('2026-01-01'));
        $calendar->addDate(new \DateTimeImmutable('2026-05-20'), '#a7f3d0', 'Graduación');
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertEquals(new \DateTimeImmutable('2026-01-01'), $data->effectiveStart);
    }

    public function testTwoIndividualDatesSharingTheSameDayAreListedSeparatelyNeverMerged(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        $calendar->addDate(new \DateTimeImmutable('2026-03-10'), '#a7f3d0', 'Entrega de notas');
        $calendar->addDate(new \DateTimeImmutable('2026-03-10'), '#a7f3d0', 'Claustro');
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        $march = $this->monthAnnotationLabels($data->months, 3);
        self::assertSame(['10', '10'], $march);
    }

    public function testANonWorkingDaysRunMergesBySameDescriptionWhileADifferentlyDescribedOneStaysApart(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Two non-working days with the same description merge; a third, differently described,
        // stays on its own even though it's the very next day.
        $holiday1 = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-19'))->setDescription('Puente')->setAcademicYear($year);
        $holiday2 = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-20'))->setDescription('Puente')->setAcademicYear($year);
        $holiday3 = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-23'))->setDescription('Día del centro')->setAcademicYear($year);
        $calendar->setStartDate(new \DateTimeImmutable('2026-03-01'));
        $calendar->setEndDate(new \DateTimeImmutable('2026-03-31'));
        $this->persist($centre, $year, $teacher, $holiday1, $holiday2, $holiday3, $calendar);

        $data = $this->builder->build($calendar);

        $march = $this->monthAnnotationLabels($data->months, 3);
        self::assertContains('19-20', $march);
        self::assertContains('23', $march);
    }

    public function testAPeriodOnlyMarksItsOverallStartAndEndDayInTheMargin(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Weekdays-with-hours from Jan 5 (Mon) to Jan 16 (Fri): weekends split it into several
        // runs of working days, but the margin must show only "Inicio FCT" (5) and "Fin FCT"
        // (16) — never one entry per week.
        $calendar->addPeriod('FCT', '#c7d2fe', false, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-01-05'), null, 80.0, 8.0, 8.0, 8.0, 8.0, 8.0);
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        $january = $data->months[0]->annotations;
        self::assertCount(2, $january);
        self::assertSame('5', $january[0]->label);
        self::assertSame('Inicio FCT', $january[0]->description);
        self::assertSame('16', $january[1]->label);
        self::assertSame('Fin FCT', $january[1]->description);
    }

    public function testASingleDayPeriodGetsOnePlainMarginEntryWithNoInicioFinLabel(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        $calendar->addPeriod('Jornada de puertas abiertas', '#fca5a5', false, PrintableCalendarPeriodMode::DateRange, new \DateTimeImmutable('2026-03-12'), new \DateTimeImmutable('2026-03-12'), null, null, null, null, null, null);
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        $march = $data->months[0]->annotations;
        self::assertCount(1, $march);
        self::assertSame('Jornada de puertas abiertas', $march[0]->description);
    }
}
