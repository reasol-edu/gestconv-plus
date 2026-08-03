<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

class DailyNoteStudentListComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    public function testRowIsHighlightedRedWhenAtThreshold(): void
    {
        [$centre, $year, $group, $teacher, $type] = $this->makeScenario('red', occurrencesForReport: 2);
        $student = $this->makeStudent('García', 'Ana', $group);
        $this->makeNote($year, $student, $group, $type, $teacher, active: true);
        $this->makeNote($year, $student, $group, $type, $teacher, active: true);
        $this->loginAs($teacher, $centre);

        $render = $this->createLiveComponent('DailyNoteStudentListComponent', ['centre' => $centre, 'viewer' => $teacher], $this->client)->render();

        $row = $render->crawler()->filter('tbody tr')->first();
        self::assertStringContainsString('bg-red-50/60', $row->attr('class'));
        self::assertStringContainsString('García, Ana', $row->text());
    }

    public function testRowIsGreyedWhenAllNotesInactive(): void
    {
        [$centre, $year, $group, $teacher, $type] = $this->makeScenario('grey', occurrencesForReport: 5);
        $student = $this->makeStudent('García', 'Ana', $group);
        $this->makeNote($year, $student, $group, $type, $teacher, active: false);
        $this->loginAs($teacher, $centre);

        $render = $this->createLiveComponent('DailyNoteStudentListComponent', ['centre' => $centre, 'viewer' => $teacher], $this->client)->render();

        $row = $render->crawler()->filter('tbody tr')->first();
        self::assertStringContainsString('bg-gray-50', $row->attr('class'));
        self::assertCount(0, $render->crawler()->filter('form[action$="/desactivar-tipo"]'));
    }

    public function testDeactivateAllButtonOnlyShownWhenThereAreActiveNotes(): void
    {
        [$centre, $year, $group, $teacher, $type] = $this->makeScenario('button', occurrencesForReport: 5);
        $student = $this->makeStudent('García', 'Ana', $group);
        $this->makeNote($year, $student, $group, $type, $teacher, active: true);
        $this->loginAs($teacher, $centre);

        $render = $this->createLiveComponent('DailyNoteStudentListComponent', ['centre' => $centre, 'viewer' => $teacher], $this->client)->render();

        self::assertCount(1, $render->crawler()->filter('form[action$="/desactivar-tipo"]'));
        self::assertCount(1, $render->crawler()->filter('a[href*="/partes/nuevo"][href*="fromDailyNotes=1"]'));
    }

    public function testSearchFiltersByStudentName(): void
    {
        [$centre, $year, $group, $teacher, $type] = $this->makeScenario('search', occurrencesForReport: 0);
        $match    = $this->makeStudent('García', 'Ana', $group);
        $noMatch  = $this->makeStudent('Ruiz', 'Pablo', $group);
        $this->makeNote($year, $match, $group, $type, $teacher, active: true);
        $this->makeNote($year, $noMatch, $group, $type, $teacher, active: true);
        $this->loginAs($teacher, $centre);

        $component = $this->createLiveComponent('DailyNoteStudentListComponent', ['centre' => $centre, 'viewer' => $teacher], $this->client);
        $render    = $component->set('search', 'García')->render();

        self::assertStringContainsString('García, Ana', $render->crawler()->filter('tbody')->text());
        self::assertStringNotContainsString('Ruiz, Pablo', $render->crawler()->filter('tbody')->text());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: EducationalCentre, 1: AcademicYear, 2: Group, 3: Teacher, 4: DailyNoteType} */
    private function makeScenario(string $suffix, int $occurrencesForReport): array
    {
        $centre  = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'dslc'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.dslc.' . $suffix . uniqid('', false));
        $group->addTutor($teacher);
        $type = (new DailyNoteType())->setEducationalCentre($centre)->setName('Tipo ' . $suffix)->setOccurrencesForReport($occurrencesForReport)->setPosition(0);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $teacher, $type);

        return [$centre, $year, $group, $teacher, $type];
    }

    private function makeStudent(string $lastName, string $firstName, Group $group): Student
    {
        $student = (new Student(new PersonName($firstName, $lastName)))->setStudentId('NIE-dslc-' . uniqid('', false));
        $student->addGroup($group);
        $this->persist($student);

        return $student;
    }

    private function makeNote(AcademicYear $year, Student $student, Group $group, DailyNoteType $type, Teacher $teacher, bool $active): DailyNote
    {
        $note = (new DailyNote())
            ->setAcademicYear($year)
            ->setStudent($student)
            ->setGroup($group)
            ->setType($type)
            ->setRegisteredBy($teacher)
            ->setActive($active);
        $this->persist($note);

        return $note;
    }
}
