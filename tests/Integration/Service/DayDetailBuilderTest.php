<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SchoolEvent;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Service\DayDetailBuilder;
use App\Tests\Integration\RepositoryTestCase;

class DayDetailBuilderTest extends RepositoryTestCase
{
    private DayDetailBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DayDetailBuilder $builder */
        $builder       = self::getContainer()->get(DayDetailBuilder::class);
        $this->builder = $builder;
    }

    public function testBuildIncludesActiveSanctionForTheDay(): void
    {
        $world    = $this->makeWorld('sanctions');
        $sanction = $this->makeSanction($world, effectiveFrom: new \DateTimeImmutable('2026-03-10'));

        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(1, $report->sanctionedStudents);
        self::assertSame($world['student']->getId()->toRfc4122(), $report->sanctionedStudents[0]->student->getId()->toRfc4122());
    }

    public function testAnAbsenceWithASubstituteIsNotShownInTheDay(): void
    {
        $world   = $this->makeWorld('substitute');
        $absence = $this->makeAbsence($world, new \DateTimeImmutable('2026-03-10'));
        $slot    = $this->makeSlot($world, 'Tramo 1', '08:00', '09:00');
        $this->persist((new \App\Entity\Activity())->setAbsence($absence)->setDate(new \DateTimeImmutable('2026-03-10'))
            ->setTimeSlot($slot)->setDescription('<p>Tarea</p>'));

        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));
        self::assertCount(1, $report->absentTeachers, 'sin sustituto/a se muestra');

        $absence->setHasSubstitute(true);
        $this->flush();
        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(0, $report->absentTeachers);
        self::assertCount(0, $report->timeSlots[0]->activities, 'ni sus tareas');
    }

    public function testATeacherWithACoveredAbsenceIsNotListedAsOnGuardThatDay(): void
    {
        $world = $this->makeWorld('guard-substitute');
        $other = $this->makeTeacher('otro-guardia');
        $slot  = $this->makeSlot($world, 'Tramo 1', '08:00', '09:00');
        $slot->addGuard($world['teacher']);
        $slot->addGuard($other);
        $absence = $this->makeAbsence($world, new \DateTimeImmutable('2026-03-10'));
        $this->flush();

        $names = static fn (array $guards): array => array_map(static fn (\App\Entity\Teacher $t): string => $t->getUsername(), $guards);
        $both  = $names($this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'))->timeSlots[0]->guards);
        self::assertCount(2, $both, 'ausente sin sustituto/a: sigue figurando');

        $absence->setHasSubstitute(true);
        $this->flush();

        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));
        self::assertSame([$other->getUsername()], $names($report->timeSlots[0]->guards));
        // Otro día en que no está ausente, sí figura.
        $slot->setDayOfWeek(((int) (new \DateTimeImmutable('2026-03-11'))->format('N')) - 1);
        $this->flush();
        self::assertCount(2, $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-11'))->timeSlots[0]->guards);
    }

    public function testBuildIncludesAbsenceOnlyForAdmin(): void
    {
        $world = $this->makeWorld('absences');
        $this->makeAbsence($world, new \DateTimeImmutable('2026-03-10'));

        $adminReport = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));
        self::assertCount(1, $adminReport->absentTeachers);

        $teacherReport = $this->builder->build($world['year'], $world['teacher'], false, new \DateTimeImmutable('2026-03-10'));
        self::assertCount(0, $teacherReport->absentTeachers);
    }

    public function testBuildIncludesGeneralEventsForAnyViewer(): void
    {
        $world = $this->makeWorld('general');
        $this->makeEvent($world, 'Claustro', general: true, date: new \DateTimeImmutable('2026-03-10'));

        $report = $this->builder->build($world['year'], $world['teacher'], false, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(1, $report->events);
        self::assertSame('Claustro', $report->events[0]->getName());
    }

    public function testBuildExcludesRestrictedEventsForUnrelatedTeacher(): void
    {
        $world  = $this->makeWorld('restricted');
        $viewer = $this->makeTeacher('unrelated');
        $this->persist($viewer);
        $this->makeEvent($world, 'Reunión ajena', general: false, groups: [$world['group']], date: new \DateTimeImmutable('2026-03-10'));

        $report = $this->builder->build($world['year'], $viewer, false, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(0, $report->events);
    }

    public function testBuildIncludesAllEventsForAdminRegardlessOfGroup(): void
    {
        $world = $this->makeWorld('adminevents');
        $this->makeEvent($world, 'General', general: true, date: new \DateTimeImmutable('2026-03-10'));
        $this->makeEvent($world, 'Restringido', general: false, groups: [$world['group']], date: new \DateTimeImmutable('2026-03-10'));

        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(2, $report->events);
    }

    public function testBuildOnNonWorkingDayStillIncludesEventsAndSanctions(): void
    {
        $world = $this->makeWorld('holiday');
        $holiday = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-19'))->setDescription('Día del centro')->setAcademicYear($world['year']);
        $this->persist($holiday);
        $this->makeEvent($world, 'Jornada de puertas abiertas', general: true, date: new \DateTimeImmutable('2026-03-19'));
        $this->makeSanction($world, effectiveFrom: new \DateTimeImmutable('2026-03-19'));

        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-19'));

        self::assertSame('Día del centro', $report->nonWorkingDayLabel);
        self::assertCount(1, $report->events);
        self::assertCount(1, $report->sanctionedStudents);
    }

    private function makeSlot(array $world, string $name, string $from, string $to): TimeSlot
    {
        // 2026-03-10 es martes -> dayOfWeek 1
        $slot = (new TimeSlot())
            ->setName($name)
            ->setDayOfWeek(1)
            ->setStartTime(new \DateTimeImmutable($from))
            ->setEndTime(new \DateTimeImmutable($to))
            ->setAcademicYear($world['year']);
        $this->persist($slot);

        return $slot;
    }

    public function testAnEventInsideATimeSlotIsShownInThatSlot(): void
    {
        $world = $this->makeWorld('eventinslot');
        $this->makeSlot($world, '1ª hora', '08:00', '08:55');
        $this->makeSlot($world, '2ª hora', '09:00', '09:55');
        $this->makeEvent($world, 'Charla', general: true); // 09:00–10:00

        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));

        self::assertSame([], $report->timeSlots[0]->events, 'no coincide con la primera hora');
        self::assertCount(1, $report->timeSlots[1]->events);
        self::assertSame('Charla', $report->timeSlots[1]->events[0]->getName());
    }

    public function testAnEventOverlappingTwoSlotsAppearsInBoth(): void
    {
        $world = $this->makeWorld('eventtwoslots');
        $this->makeSlot($world, '1ª hora', '08:30', '09:25');
        $this->makeSlot($world, '2ª hora', '09:30', '10:25');
        $this->makeEvent($world, 'Taller', general: true); // 09:00–10:00

        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(1, $report->timeSlots[0]->events);
        self::assertCount(1, $report->timeSlots[1]->events);
    }

    public function testASlotThatOnlyTouchesTheEventEdgeDoesNotShowIt(): void
    {
        $world = $this->makeWorld('eventedge');
        $this->makeSlot($world, '1ª hora', '08:00', '09:00'); // acaba cuando empieza el evento
        $this->makeEvent($world, 'Charla', general: true);

        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));

        self::assertSame([], $report->timeSlots[0]->events);
    }

    public function testEventsOutsideAnySlotAreListedApart(): void
    {
        $world = $this->makeWorld('eventoutside');
        $this->makeSlot($world, '1ª hora', '08:00', '08:55');
        $this->makeEvent($world, 'Charla', general: true); // 09:00–10:00, sin tramo

        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(1, $report->events, 'sigue en el listado completo del día');
        self::assertCount(1, \App\Service\BoardTodayTimeSlot::eventsOutside($report->timeSlots, $report->events));
    }

    public function testASlotOnlyShowsTheEventsTheViewerCanSee(): void
    {
        $world  = $this->makeWorld('eventvisibility');
        $viewer = $this->makeTeacher('unrelated-slot');
        $this->persist($viewer);
        $this->makeSlot($world, '2ª hora', '09:00', '09:55');
        $this->makeEvent($world, 'Reunión ajena', general: false, groups: [$world['group']]);

        $report = $this->builder->build($world['year'], $viewer, false, new \DateTimeImmutable('2026-03-10'));

        self::assertSame([], $report->timeSlots[0]->events);
    }

    public function testBuildIncludesTimeSlotsForTheWeekday(): void
    {
        $world = $this->makeWorld('slots');
        // 2026-03-10 es martes (N=2) -> dayOfWeek 1 (0=lunes)
        $slot = (new TimeSlot())
            ->setName('1ª hora')
            ->setDayOfWeek(1)
            ->setStartTime(new \DateTimeImmutable('08:00'))
            ->setEndTime(new \DateTimeImmutable('08:55'))
            ->setAcademicYear($world['year']);
        $this->persist($slot);

        $report = $this->builder->build($world['year'], null, true, new \DateTimeImmutable('2026-03-10'));

        self::assertCount(1, $report->timeSlots);
        self::assertSame('1ª hora', $report->timeSlots[0]->timeSlot->getName());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear, course: Course, group: Group, student: Student, teacher: Teacher} */
    private function makeWorld(string $suffix): array
    {
        $centre  = (new EducationalCentre())->setCode('41' . substr(md5($suffix . 'ddb'), 0, 6))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $teacher = $this->makeTeacher($suffix);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-DDB-' . uniqid('', false));
        $student->addGroup($group);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $teacher, $student);

        return compact('centre', 'year', 'course', 'group', 'student', 'teacher');
    }

    private function makeTeacher(string $suffix): Teacher
    {
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.ddb.' . $suffix . uniqid('', false));
        $this->persist($teacher);

        return $teacher;
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, course: Course, group: Group, student: Student, teacher: Teacher} $world */
    private function makeSanction(array $world, \DateTimeImmutable $effectiveFrom): Sanction
    {
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($world['teacher'])
            ->setDetails('Detalle de la sanción')
            ->setNoMeasureApplied(true)
            ->setEffectiveFrom($effectiveFrom);
        $this->persist($sanction);

        $this->notifySanction($sanction, $world['teacher']);

        return $sanction;
    }

    private function notifySanction(Sanction $sanction, Teacher $teacher): void
    {
        $method = (new \App\Entity\CommunicationMethod())
            ->setEducationalCentre($sanction->getAcademicYear()->getEducationalCentre())
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($method);

        $communication = \App\Entity\Communication::forSanction($sanction, $method, $teacher, new \DateTimeImmutable(), \App\Entity\CommunicationResult::Notified);
        $this->persist($communication);
        $sanction->setNotifiedCommunication($communication);
        $this->flush();
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, course: Course, group: Group, student: Student, teacher: Teacher} $world */
    private function makeAbsence(array $world, \DateTimeImmutable $date): Absence
    {
        $absence = (new Absence())
            ->setTeacher($world['teacher'])
            ->setAcademicYear($world['year'])
            ->setStartDate($date)
            ->setEndDate($date);
        $this->persist($absence);

        return $absence;
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, course: Course, group: Group, student: Student, teacher: Teacher} $world
     * @param list<Group> $groups
     */
    private function makeEvent(array $world, string $name, bool $general, array $groups = [], ?\DateTimeImmutable $date = null): SchoolEvent
    {
        $event = (new SchoolEvent())
            ->setAcademicYear($world['year'])
            ->setDate($date ?? new \DateTimeImmutable('2026-03-10'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName($name)
            ->setGeneral($general);

        foreach ($groups as $group) {
            $event->addGroup($group);
        }

        $this->persist($event);

        return $event;
    }
}
