<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\GroupTeacherRepository;
use App\Repository\SanctionTaskRepository;
use App\Service\SanctionTaskGenerator;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

class TeacherSubjectsComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    // ── addSubject ───────────────────────────────────────────────────────────

    public function testAddSubjectCreatesAssignment(): void
    {
        [$admin, $centre, $year, $course, $group, $teacher] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:TeacherSubjectsComponent', ['centre' => $centre, 'teacher' => $teacher], $this->client);
        $component->set('newGroupId', $group->getId()->toRfc4122())->set('newSubject', 'Física')->call('addSubject');

        $this->em->clear();
        $refreshed = $this->em->find(Teacher::class, $teacher->getId());
        /** @var GroupTeacherRepository $groupTeachers */
        $groupTeachers = self::getContainer()->get(GroupTeacherRepository::class);
        $assignments   = $groupTeachers->findByTeacherAndAcademicYearOrdered($refreshed, $this->em->find(AcademicYear::class, $year->getId()));

        self::assertCount(1, $assignments);
        self::assertSame('Física', $assignments[0]->getSubject());
    }

    public function testAddSubjectRejectsDuplicate(): void
    {
        [$admin, $centre, $year, $course, $group, $teacher] = $this->makeScenario();
        $group->addTeacher($teacher, 'Matemáticas');
        $this->flush();
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:TeacherSubjectsComponent', ['centre' => $centre, 'teacher' => $teacher], $this->client);
        $component->set('newGroupId', $group->getId()->toRfc4122())->set('newSubject', 'Matemáticas')->call('addSubject');

        $this->em->clear();
        /** @var GroupTeacherRepository $groupTeachers */
        $groupTeachers = self::getContainer()->get(GroupTeacherRepository::class);
        $assignments   = $groupTeachers->findByTeacherAndAcademicYearOrdered(
            $this->em->find(Teacher::class, $teacher->getId()),
            $this->em->find(AcademicYear::class, $year->getId()),
        );
        self::assertCount(1, $assignments);
    }

    // ── saveEdit ─────────────────────────────────────────────────────────────

    public function testSaveEditChangesGroupAndSubject(): void
    {
        [$admin, $centre, $year, $course, $group, $teacher] = $this->makeScenario();
        $group->addTeacher($teacher, 'Matemáticas');
        $otherGroup = (new Group())->setName('1ºB')->setCourse($course);
        $this->persist($otherGroup);
        $assignment = $group->getTeacherAssignments()->first();
        self::assertInstanceOf(GroupTeacher::class, $assignment);
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:TeacherSubjectsComponent', ['centre' => $centre, 'teacher' => $teacher], $this->client);
        $component
            ->set('editingId', $assignment->getId()->toRfc4122())
            ->set('editGroupId', $otherGroup->getId()->toRfc4122())
            ->set('editSubject', 'Física')
            ->call('saveEdit');

        $this->em->clear();
        /** @var GroupTeacherRepository $groupTeachers */
        $groupTeachers = self::getContainer()->get(GroupTeacherRepository::class);
        $assignments   = $groupTeachers->findByTeacherAndAcademicYearOrdered(
            $this->em->find(Teacher::class, $teacher->getId()),
            $this->em->find(AcademicYear::class, $year->getId()),
        );

        self::assertCount(1, $assignments);
        self::assertSame('Física', $assignments[0]->getSubject());
        self::assertSame('1ºB', $assignments[0]->getGroup()->getName());
    }

    public function testSaveEditBlockedWhenAssignmentHasPendingSanctionTasks(): void
    {
        [$admin, $centre, $year, $course, $group, $teacher] = $this->makeScenario();
        $group->addTeacher($teacher, 'Matemáticas');
        $assignment = $group->getTeacherAssignments()->first();
        self::assertInstanceOf(GroupTeacher::class, $assignment);
        $this->flush();

        $this->generateSanctionTasksFor($centre, $year, $group, $teacher);
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:TeacherSubjectsComponent', ['centre' => $centre, 'teacher' => $teacher], $this->client);
        $component
            ->set('editingId', $assignment->getId()->toRfc4122())
            ->set('editGroupId', $group->getId()->toRfc4122())
            ->set('editSubject', 'Física')
            ->call('saveEdit');

        $this->em->clear();
        /** @var SanctionTaskRepository $sanctionTasks */
        $sanctionTasks = self::getContainer()->get(SanctionTaskRepository::class);
        self::assertTrue($sanctionTasks->existsForGroupTeacher($this->em->find(GroupTeacher::class, $assignment->getId())));

        /** @var GroupTeacherRepository $groupTeachers */
        $groupTeachers = self::getContainer()->get(GroupTeacherRepository::class);
        $assignments   = $groupTeachers->findByTeacherAndAcademicYearOrdered(
            $this->em->find(Teacher::class, $teacher->getId()),
            $this->em->find(AcademicYear::class, $year->getId()),
        );
        self::assertCount(1, $assignments);
        self::assertSame('Matemáticas', $assignments[0]->getSubject());
    }

    // ── deleteSubject ────────────────────────────────────────────────────────

    public function testDeleteSubjectRemovesAssignment(): void
    {
        [$admin, $centre, $year, $course, $group, $teacher] = $this->makeScenario();
        $group->addTeacher($teacher, 'Matemáticas');
        $assignment = $group->getTeacherAssignments()->first();
        self::assertInstanceOf(GroupTeacher::class, $assignment);
        $this->flush();
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:TeacherSubjectsComponent', ['centre' => $centre, 'teacher' => $teacher], $this->client);
        $component->call('deleteSubject', ['id' => $assignment->getId()->toRfc4122()]);

        $this->em->clear();
        /** @var GroupTeacherRepository $groupTeachers */
        $groupTeachers = self::getContainer()->get(GroupTeacherRepository::class);
        $assignments   = $groupTeachers->findByTeacherAndAcademicYearOrdered(
            $this->em->find(Teacher::class, $teacher->getId()),
            $this->em->find(AcademicYear::class, $year->getId()),
        );
        self::assertCount(0, $assignments);
    }

    public function testDeleteSubjectBlockedWhenAssignmentHasPendingSanctionTasks(): void
    {
        [$admin, $centre, $year, $course, $group, $teacher] = $this->makeScenario();
        $group->addTeacher($teacher, 'Matemáticas');
        $assignment = $group->getTeacherAssignments()->first();
        self::assertInstanceOf(GroupTeacher::class, $assignment);
        $this->flush();

        $this->generateSanctionTasksFor($centre, $year, $group, $teacher);
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:TeacherSubjectsComponent', ['centre' => $centre, 'teacher' => $teacher], $this->client);
        $component->call('deleteSubject', ['id' => $assignment->getId()->toRfc4122()]);

        $this->em->clear();
        /** @var GroupTeacherRepository $groupTeachers */
        $groupTeachers = self::getContainer()->get(GroupTeacherRepository::class);
        $assignments   = $groupTeachers->findByTeacherAndAcademicYearOrdered(
            $this->em->find(Teacher::class, $teacher->getId()),
            $this->em->find(AcademicYear::class, $year->getId()),
        );
        self::assertCount(1, $assignments);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear, 3: Course, 4: Group, 5: Teacher} */
    private function makeScenario(): array
    {
        $admin   = (new Teacher(new PersonName('Admin', 'User')))->setUsername('admin.tsc')->setAdmin(true);
        $centre  = (new EducationalCentre())->setCode('41000002')->setName('IES Materias Test')->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.tsc');

        $this->persist($admin, $centre, $year, $course, $group, $teacher);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);
        $this->flush();

        return [$admin, $centre, $year, $course, $group, $teacher];
    }

    private function generateSanctionTasksFor(EducationalCentre $centre, AcademicYear $year, Group $group, Teacher $teacher): void
    {
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-tsc-1');

        $category = (new SanctionMeasureCategory())
            ->setEducationalCentre($centre)
            ->setName('Correcciones')
            ->setPosition(0);
        $measure = (new SanctionMeasure())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Expulsión con actividades')
            ->setHasDateRange(true)
            ->setPosition(0)
            ->setActive(true);
        $sanction = (new Sanction())
            ->setAcademicYear($year)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($teacher)
            ->setDetails('Detalles de prueba')
            ->setNoMeasureApplied(false)
            ->setEffectiveFrom(new \DateTimeImmutable('+2 days'))
            ->setEffectiveTo(new \DateTimeImmutable('+7 days'));
        $sanction->addMeasure($measure);
        $this->persist($student, $category, $measure, $sanction);

        /** @var SanctionTaskGenerator $generator */
        $generator = self::getContainer()->get(SanctionTaskGenerator::class);
        $tasks     = $generator->generateFor($sanction);
        self::assertCount(1, $tasks);
        $this->flush();
    }
}
