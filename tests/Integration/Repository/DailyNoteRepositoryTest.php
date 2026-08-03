<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\DailyNoteRepository;
use App\Tests\Integration\RepositoryTestCase;

class DailyNoteRepositoryTest extends RepositoryTestCase
{
    private DailyNoteRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DailyNoteRepository $repository */
        $repository       = self::getContainer()->get(DailyNoteRepository::class);
        $this->repository = $repository;
    }

    // ── findStudentSummaryByType ────────────────────────────────────────────

    public function testFindStudentSummaryByTypeCountsActiveAndInactiveNotes(): void
    {
        $world = $this->makeWorld('summary');
        $this->makeNote($world, $world['student'], $world['group'], $world['type'], active: true, occurredAt: new \DateTimeImmutable('-5 days'));
        $this->makeNote($world, $world['student'], $world['group'], $world['type'], active: true, occurredAt: new \DateTimeImmutable('-1 day'));
        $this->makeNote($world, $world['student'], $world['group'], $world['type'], active: false, occurredAt: new \DateTimeImmutable('-10 days'));

        $rows = $this->repository->findStudentSummaryByType($world['centre'], $world['teacher'], $world['year'], $world['type']);

        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]['activeCount']);
        self::assertSame(1, $rows[0]['inactiveCount']);
        self::assertSame((new \DateTimeImmutable('-5 days'))->format('Y-m-d H:i'), $rows[0]['activeFrom']->format('Y-m-d H:i'));
        self::assertSame((new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i'), $rows[0]['activeTo']->format('Y-m-d H:i'));
    }

    public function testFindStudentSummaryByTypeOrdersByActiveCountThenGroupThenName(): void
    {
        $world = $this->makeWorld('order');
        $other = $this->makeStudent('Beltrán', 'Suárez', $world['group']);
        $this->persist($other);

        // $world['student'] es 'García, Ana' con 1 nota activa; $other es 'Beltrán, Suárez' con 2.
        $this->makeNote($world, $world['student'], $world['group'], $world['type'], active: true);
        $this->makeNote($world, $other, $world['group'], $world['type'], active: true);
        $this->makeNote($world, $other, $world['group'], $world['type'], active: true);

        $rows = $this->repository->findStudentSummaryByType($world['centre'], $world['teacher'], $world['year'], $world['type']);

        self::assertCount(2, $rows);
        self::assertSame('Beltrán', $rows[0]['lastName']);
        self::assertSame(2, $rows[0]['activeCount']);
        self::assertSame('García', $rows[1]['lastName']);
        self::assertSame(1, $rows[1]['activeCount']);
    }

    public function testFindStudentSummaryByTypeRestrictsNonAdminToTutoredGroups(): void
    {
        $world       = $this->makeWorld('restrict');
        $otherGroup  = (new Group())->setName('1ºB')->setCourse($world['course']);
        $otherStudent = $this->makeStudent('Ruiz', 'Pablo', $otherGroup);
        $this->persist($otherGroup, $otherStudent);

        $this->makeNote($world, $world['student'], $world['group'], $world['type'], active: true);
        $this->makeNote($world, $otherStudent, $otherGroup, $world['type'], active: true);

        $tutorRows = $this->repository->findStudentSummaryByType($world['centre'], $world['teacher'], $world['year'], $world['type']);
        self::assertCount(1, $tutorRows);
        self::assertSame('García', $tutorRows[0]['lastName']);

        $admin = $this->makeTeacher('admin.restrict');
        $world['centre']->addAdmin($admin);
        $this->persist($admin);

        $adminRows = $this->repository->findStudentSummaryByType($world['centre'], $admin, $world['year'], $world['type']);
        self::assertCount(2, $adminRows);
    }

    // ── deactivateAllActiveForStudent ───────────────────────────────────────

    public function testDeactivateAllActiveForStudentDeactivatesAllTypesInYear(): void
    {
        $world     = $this->makeWorld('deactivate-all');
        $otherType = (new DailyNoteType())->setEducationalCentre($world['centre'])->setName('Otro tipo')->setPosition(1);
        $this->persist($otherType);

        $note1 = $this->makeNote($world, $world['student'], $world['group'], $world['type'], active: true);
        $note2 = $this->makeNote($world, $world['student'], $world['group'], $otherType, active: true);

        $count = $this->repository->deactivateAllActiveForStudent($world['student'], $world['year']);

        self::assertSame(2, $count);
        $this->em->clear();
        self::assertFalse($this->em->find(DailyNote::class, $note1->getId())->isActive());
        self::assertFalse($this->em->find(DailyNote::class, $note2->getId())->isActive());
    }

    // ── deactivateExpiredByType ──────────────────────────────────────────────

    public function testDeactivateExpiredByTypeOnlyDeactivatesNotesAtOrBeforeCutoff(): void
    {
        $world = $this->makeWorld('expire');
        $old   = $this->makeNote($world, $world['student'], $world['group'], $world['type'], active: true, occurredAt: new \DateTimeImmutable('-40 days'));
        $recent = $this->makeNote($world, $world['student'], $world['group'], $world['type'], active: true, occurredAt: new \DateTimeImmutable('-2 days'));

        $count = $this->repository->deactivateExpiredByType($world['type'], new \DateTimeImmutable('-30 days'));

        self::assertSame(1, $count);
        $this->em->clear();
        self::assertFalse($this->em->find(DailyNote::class, $old->getId())->isActive());
        self::assertTrue($this->em->find(DailyNote::class, $recent->getId())->isActive());
    }

    // ── findStudentsAtThreshold ──────────────────────────────────────────────

    public function testFindStudentsAtThresholdOnlyReturnsRowsAtOrAboveThreshold(): void
    {
        $world = $this->makeWorld('threshold', occurrencesForReport: 2);

        $this->makeNote($world, $world['student'], $world['group'], $world['type'], active: true);

        self::assertSame([], $this->repository->findStudentsAtThreshold($world['centre'], $world['teacher'], $world['year']));

        $this->makeNote($world, $world['student'], $world['group'], $world['type'], active: true);

        $rows = $this->repository->findStudentsAtThreshold($world['centre'], $world['teacher'], $world['year']);
        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]['activeCount']);
        self::assertSame(2, $rows[0]['threshold']);
        self::assertSame($world['type']->getId()->toRfc4122(), $rows[0]['typeId']);
    }

    public function testFindStudentsAtThresholdRestrictsNonAdminToTutoredGroups(): void
    {
        $world      = $this->makeWorld('threshold-restrict', occurrencesForReport: 1);
        $otherGroup = (new Group())->setName('1ºB')->setCourse($world['course']);
        $otherStudent = $this->makeStudent('Ruiz', 'Pablo', $otherGroup);
        $this->persist($otherGroup, $otherStudent);

        $this->makeNote($world, $otherStudent, $otherGroup, $world['type'], active: true);

        $tutorRows = $this->repository->findStudentsAtThreshold($world['centre'], $world['teacher'], $world['year']);
        self::assertSame([], $tutorRows);

        $admin = $this->makeTeacher('admin.threshold.restrict');
        $world['centre']->addAdmin($admin);
        $this->persist($admin);

        $adminRows = $this->repository->findStudentsAtThreshold($world['centre'], $admin, $world['year']);
        self::assertCount(1, $adminRows);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear, course: Course, group: Group, student: Student, teacher: Teacher, type: DailyNoteType} */
    private function makeWorld(string $suffix, int $occurrencesForReport = 0): array
    {
        $centre  = (new EducationalCentre())->setCode('41' . substr(md5($suffix . 'dn'), 0, 6))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $teacher = $this->makeTeacher($suffix);
        $group->addTutor($teacher);
        $student = $this->makeStudent('García', 'Ana', $group);
        $type    = (new DailyNoteType())->setEducationalCentre($centre)->setName('Tipo ' . $suffix)->setOccurrencesForReport($occurrencesForReport)->setPosition(0);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $teacher, $student, $type);

        return compact('centre', 'year', 'course', 'group', 'student', 'teacher', 'type');
    }

    private function makeStudent(string $lastName, string $firstName, Group $group): Student
    {
        $student = (new Student(new PersonName($firstName, $lastName)))->setStudentId('NIE-' . uniqid('', false));
        $student->addGroup($group);

        return $student;
    }

    private function makeTeacher(string $suffix): Teacher
    {
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.dnr.' . $suffix . uniqid('', false));
        $this->persist($teacher);

        return $teacher;
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, course: Course, group: Group, student: Student, teacher: Teacher, type: DailyNoteType} $world */
    private function makeNote(array $world, Student $student, Group $group, DailyNoteType $type, bool $active, ?\DateTimeImmutable $occurredAt = null): DailyNote
    {
        $note = (new DailyNote())
            ->setAcademicYear($world['year'])
            ->setStudent($student)
            ->setGroup($group)
            ->setType($type)
            ->setRegisteredBy($world['teacher'])
            ->setActive($active);

        if ($occurredAt !== null) {
            $note->setOccurredAt($occurredAt);
        }

        $this->persist($note);

        return $note;
    }
}
