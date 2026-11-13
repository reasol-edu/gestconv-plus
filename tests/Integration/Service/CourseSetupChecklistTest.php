<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\SetupStepConfirmation;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Service\CourseSetupChecklist;
use App\Service\Feature;
use App\Service\SetupChecklist;
use App\Service\SetupStep;
use App\Tests\Integration\RepositoryTestCase;

/** Lista de comprobación de inicio de curso: qué pasos se detectan solos y cuáles se confirman a mano. */
class CourseSetupChecklistTest extends RepositoryTestCase
{
    private CourseSetupChecklist $checklist;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CourseSetupChecklist $checklist */
        $checklist       = self::getContainer()->get(CourseSetupChecklist::class);
        $this->checklist = $checklist;
    }

    public function testWithoutAnActiveYearOnlyTheFirstStepCanBeDone(): void
    {
        $centre = $this->centre('a');
        $this->persist($centre);

        $list = $this->checklist->build($centre);

        self::assertNull($list->year);
        self::assertCount(count(SetupStep::cases()), $list->items);
        foreach ($list->items as $item) {
            self::assertFalse($item->done);
            self::assertSame($item->step !== SetupStep::ActiveYear, $item->blocked, $item->step->value);
        }
        self::assertFalse($list->isComplete());
        self::assertSame(0, $list->percent());
    }

    public function testAFreshYearHasOnlyTheYearDoneAndSixRequiredSteps(): void
    {
        $w    = $this->world('b');
        $list = $this->checklist->build($w['centre']);

        // Curso, profesorado, alumnado, tutorías y catálogos; y los tramos, porque por defecto la sección de ausencias está activa.
        self::assertSame(6, $list->requiredTotal());
        self::assertTrue($this->item($list, SetupStep::ActiveYear)->done);
        self::assertSame(1, $list->requiredDone());
        self::assertSame(16, $list->percent());
        self::assertFalse($list->isComplete());
        self::assertSame(
            ['teachers', 'students', 'tutors', 'catalogues', 'time_slots'],
            array_map(static fn ($i): string => $i->step->value, $list->pendingRequired()),
        );
        // Los dos grupos del montaje todavía no tienen tutoría.
        self::assertSame('setup.detail.tutors_missing', $this->item($list, SetupStep::Tutors)->detailKey);
        self::assertSame(['%missing%' => 2, '%count%' => 2], $this->item($list, SetupStep::Tutors)->detailParams);
    }

    public function testEachDetectedStepTurnsDoneWithItsData(): void
    {
        $w = $this->world('c');
        $this->populate($w);
        $list = $this->checklist->build($w['centre']);

        self::assertTrue($this->item($list, SetupStep::Teachers)->done);
        self::assertSame(1, $this->item($list, SetupStep::Teachers)->detailParams['%count%']);
        self::assertTrue($this->item($list, SetupStep::Students)->done);
        self::assertSame(['%students%' => 1, '%groups%' => 2], $this->item($list, SetupStep::Students)->detailParams);
        self::assertFalse($this->item($list, SetupStep::GroupTeachers)->done);
    }

    public function testTutorsReportsHowManyGroupsAreMissingOne(): void
    {
        $w = $this->world('d');
        $this->populate($w);
        $w['groupA']->addTutor($w['teacher']);
        $this->flush();

        $tutorsItem = $this->item($this->checklist->build($w['centre']), SetupStep::Tutors);
        self::assertFalse($tutorsItem->done);
        self::assertSame('setup.detail.tutors_missing', $tutorsItem->detailKey);
        self::assertSame(['%missing%' => 1, '%count%' => 2], $tutorsItem->detailParams);

        $w['groupB']->addTutor($w['teacher']);
        $this->flush();

        $tutorsItem = $this->item($this->checklist->build($w['centre']), SetupStep::Tutors);
        self::assertTrue($tutorsItem->done);
        self::assertSame('setup.detail.tutors_all', $tutorsItem->detailKey);
    }

    public function testWithoutGroupsTheTutorsAndStudentsStepsCannotBeDone(): void
    {
        $centre = $this->centre('nogroups');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year);

        $list = $this->checklist->build($centre);

        self::assertFalse($this->item($list, SetupStep::Tutors)->done, 'Cero grupos sin tutor no significa que haya tutorías.');
        self::assertSame('setup.detail.no_groups', $this->item($list, SetupStep::Tutors)->detailKey);
        self::assertFalse($this->item($list, SetupStep::Students)->done);
    }

    public function testManualStepsAreOnlyDoneWhenConfirmedForTheActiveYear(): void
    {
        $w = $this->world('e');
        $item = $this->item($this->checklist->build($w['centre']), SetupStep::Catalogues);
        self::assertFalse($item->done);
        self::assertTrue($item->required);

        $this->persist(new SetupStepConfirmation($w['year'], SetupStep::Catalogues->value, $w['teacher']));
        $item = $this->item($this->checklist->build($w['centre']), SetupStep::Catalogues);

        self::assertTrue($item->done);
        self::assertSame('Test Docente', $item->confirmedBy);
        self::assertNotNull($item->confirmedAt);
        self::assertFalse($this->item($this->checklist->build($w['centre']), SetupStep::Settings)->done, 'Cada paso se confirma por separado.');
    }

    public function testAConfirmationBelongsToItsYearAndAFreshYearStartsClean(): void
    {
        $w = $this->world('f');
        $this->persist(new SetupStepConfirmation($w['year'], SetupStep::Catalogues->value, null));
        self::assertTrue($this->item($this->checklist->build($w['centre']), SetupStep::Catalogues)->done);

        $next = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($w['centre']);
        $w['centre']->setActiveAcademicYear($next);
        $this->persist($next);

        self::assertFalse($this->item($this->checklist->build($w['centre']), SetupStep::Catalogues)->done);
    }

    public function testTimeSlotsAreRequiredByDefaultBecauseAbsencesAreOn(): void
    {
        $w = $this->world('g');

        self::assertTrue($this->item($this->checklist->build($w['centre']), SetupStep::TimeSlots)->required);
    }

    public function testTimeSlotsAreOptionalWhenTheCentreSwitchedAbsencesOffAndDetectedWhenPresent(): void
    {
        // El ajuste existe antes de la primera evaluación (AppSettings guarda en memoria lo que lee la primera vez).
        $w          = $this->world('g2');
        $definition = (new SettingDefinition())->setKey(Feature::Absences->setting())->setType(SettingType::Boolean)
            ->setCategory('settings.category.features')->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new CentreSettingValue())->setDefinition($definition)->setCentre($w['centre'])->setValue('false'));
        $this->persist((new TimeSlot())->setAcademicYear($w['year'])->setDayOfWeek(1)->setStartTime(new \DateTimeImmutable('08:00'))->setEndTime(new \DateTimeImmutable('09:00'))->setName('1ª hora'));

        $list = $this->checklist->build($w['centre']);

        self::assertFalse($this->item($list, SetupStep::TimeSlots)->required);
        self::assertTrue($this->item($list, SetupStep::TimeSlots)->done, 'Detecta los tramos cuando los hay, sean o no obligatorios.');
        self::assertSame(5, $list->requiredTotal());
    }

    public function testEverythingRequiredDoneMakesTheYearComplete(): void
    {
        $w = $this->world('h');
        $this->populate($w);
        $w['groupA']->addTutor($w['teacher']);
        $w['groupB']->addTutor($w['teacher']);
        $this->persist(new SetupStepConfirmation($w['year'], SetupStep::Catalogues->value, $w['teacher']));
        $this->persist((new TimeSlot())->setAcademicYear($w['year'])->setDayOfWeek(1)->setStartTime(new \DateTimeImmutable('08:00'))->setEndTime(new \DateTimeImmutable('09:00'))->setName('1ª hora'));

        $list = $this->checklist->build($w['centre']);

        self::assertTrue($list->isComplete());
        self::assertSame(100, $list->percent());
        self::assertSame([], $list->pendingRequired());
        self::assertFalse($this->item($list, SetupStep::Settings)->done, 'Los opcionales pueden seguir pendientes.');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function item(SetupChecklist $list, SetupStep $step): \App\Service\SetupChecklistItem
    {
        foreach ($list->items as $item) {
            if ($item->step === $step) {
                return $item;
            }
        }
        self::fail('Falta el paso ' . $step->value);
    }

    private function centre(string $suffix): EducationalCentre
    {
        return (new EducationalCentre())->setCode('44' . substr(md5($suffix . 'scl'), 0, 6))->setName('IES ' . $suffix)->setCity('Sevilla');
    }

    /** @return array{centre: EducationalCentre, year: AcademicYear, teacher: Teacher, groupA: Group, groupB: Group} */
    private function world(string $suffix): array
    {
        $centre  = $this->centre($suffix);
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $groupA  = (new Group())->setName('1ºA')->setCourse($course);
        $groupB  = (new Group())->setName('1ºB')->setCourse($course);
        $teacher = (new Teacher(new PersonName('Test', 'Docente')))->setUsername('teacher.scl.' . $suffix);
        $this->persist($centre, $year, $course, $groupA, $groupB, $teacher);

        return ['centre' => $centre, 'year' => $year, 'teacher' => $teacher, 'groupA' => $groupA, 'groupB' => $groupB];
    }

    /**
     * Un docente en el curso y un estudiante en el primer grupo.
     *
     * @param array{centre: EducationalCentre, year: AcademicYear, teacher: Teacher, groupA: Group, groupB: Group} $w
     */
    private function populate(array $w): void
    {
        $w['year']->addTeacher($w['teacher']);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-scl-' . spl_object_id($w['year']));
        $student->addGroup($w['groupA']);
        $this->persist($student);
        $this->flush();
    }
}
