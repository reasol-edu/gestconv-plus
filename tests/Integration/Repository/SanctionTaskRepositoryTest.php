<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\SanctionTaskRepository;
use App\Service\SanctionTaskGenerator;
use App\Tests\Integration\RepositoryTestCase;

class SanctionTaskRepositoryTest extends RepositoryTestCase
{
    private SanctionTaskRepository $repository;
    private SanctionTaskGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SanctionTaskRepository $repository */
        $repository       = self::getContainer()->get(SanctionTaskRepository::class);
        $this->repository = $repository;

        /** @var SanctionTaskGenerator $generator */
        $generator       = self::getContainer()->get(SanctionTaskGenerator::class);
        $this->generator = $generator;
    }

    public function testGeneratesOneTaskPerGroupTeacherWhenMeasureRequiresDates(): void
    {
        $world = $this->makeWorld('gen');
        $world['group']->addTeacher($this->makeTeacher('other'), 'Física');
        $this->flush();

        $sanction = $this->makeSanction($world, requiresDates: true);

        $tasks = $this->generator->generateFor($sanction);

        self::assertCount(2, $tasks);
        self::assertCount(2, $this->repository->findBySanction($sanction));
    }

    public function testDoesNotGenerateTasksWhenMeasureDoesNotRequireDates(): void
    {
        $world    = $this->makeWorld('nogen');
        $sanction = $this->makeSanction($world, requiresDates: false);

        $tasks = $this->generator->generateFor($sanction);

        self::assertSame([], $tasks);
        self::assertSame([], $this->repository->findBySanction($sanction));
    }

    public function testFindForTeacherListsPendingTasksBeforeCompletedOnes(): void
    {
        $world  = $this->makeWorld('order');
        $other  = $this->makeTeacher('order-other');
        $world['group']->addTeacher($other, 'Física');
        $this->flush();

        $sanctionA = $this->makeSanction($world, requiresDates: true);
        $tasksA    = $this->generator->generateFor($sanctionA);
        foreach ($tasksA as $task) {
            if ($task->getGroupTeacher()->getTeacher() === $world['teacher']) {
                $task->setCompletedAt(new \DateTimeImmutable());
            }
        }
        $this->flush();

        $result = $this->repository->findForTeacher($world['centre'], $world['teacher'], $world['year']);

        self::assertCount(1, $result);
        self::assertNotNull($result[0]->getCompletedAt());
    }

    public function testFindForTeacherOnlyReturnsOwnTasks(): void
    {
        $world = $this->makeWorld('own');
        $other = $this->makeTeacher('own-other');
        $world['group']->addTeacher($other, 'Física');
        $this->flush();

        $sanction = $this->makeSanction($world, requiresDates: true);
        $this->generator->generateFor($sanction);

        $result = $this->repository->findForTeacher($world['centre'], $other, $world['year']);

        self::assertCount(1, $result);
        self::assertSame($other, $result[0]->getGroupTeacher()->getTeacher());
    }

    public function testCountPendingForTeacherCountsOnlyIncompleteOwnTasks(): void
    {
        $world    = $this->makeWorld('count');
        $sanction = $this->makeSanction($world, requiresDates: true);
        $tasks    = $this->generator->generateFor($sanction);
        self::assertCount(1, $tasks);

        self::assertSame(1, $this->repository->countPendingForTeacher($world['centre'], $world['teacher'], $world['year']));

        $tasks[0]->setCompletedAt(new \DateTimeImmutable());
        $this->flush();

        self::assertSame(0, $this->repository->countPendingForTeacher($world['centre'], $world['teacher'], $world['year']));
    }

    public function testCountSanctionsWithIncompleteTasksVisibleToAdmin(): void
    {
        $world = $this->makeWorld('admin');
        $admin = $this->makeTeacher('admin-viewer');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);

        $sanction = $this->makeSanction($world, requiresDates: true);
        $this->generator->generateFor($sanction);

        self::assertSame(
            1,
            $this->repository->countSanctionsWithIncompleteTasks($world['centre'], $admin, $world['year']),
        );
    }

    public function testCountSanctionsWithIncompleteTasksNotVisibleToUnrelatedTeacher(): void
    {
        $world      = $this->makeWorld('unrelated');
        $unrelated  = $this->makeTeacher('unrelated-viewer');
        $sanction   = $this->makeSanction($world, requiresDates: true);
        $this->generator->generateFor($sanction);

        self::assertSame(
            0,
            $this->repository->countSanctionsWithIncompleteTasks($world['centre'], $unrelated, $world['year']),
        );
    }

    public function testCountSanctionsWithIncompleteTasksVisibleToGroupTutor(): void
    {
        $world = $this->makeWorld('tutor');
        $tutor = $this->makeTeacher('tutor-viewer');
        $world['group']->addTutor($tutor);
        $this->persist($world['group']);

        $sanction = $this->makeSanction($world, requiresDates: true);
        $this->makeReport($world, $sanction);
        $this->generator->generateFor($sanction);

        self::assertSame(
            1,
            $this->repository->countSanctionsWithIncompleteTasks($world['centre'], $tutor, $world['year']),
        );
    }

    public function testCountSanctionsWithIncompleteTasksExcludesFullyCompletedSanctions(): void
    {
        $world    = $this->makeWorld('done');
        $admin    = $this->makeTeacher('done-viewer');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);

        $sanction = $this->makeSanction($world, requiresDates: true);
        $tasks    = $this->generator->generateFor($sanction);
        foreach ($tasks as $task) {
            $task->setCompletedAt(new \DateTimeImmutable());
        }
        $this->flush();

        self::assertSame(
            0,
            $this->repository->countSanctionsWithIncompleteTasks($world['centre'], $admin, $world['year']),
        );
    }

    public function testExistsForGroupTeacherReflectsWhetherATaskReferencesIt(): void
    {
        $world    = $this->makeWorld('exists');
        $sanction = $this->makeSanction($world, requiresDates: true);
        $this->generator->generateFor($sanction);

        self::assertTrue($this->repository->existsForGroupTeacher($world['groupTeacher']));

        $other = $this->makeTeacher('exists-other');
        $world['group']->addTeacher($other, 'Física');
        $this->flush();
        $otherGroupTeacher = $world['group']->getTeacherAssignments()->last();
        self::assertInstanceOf(GroupTeacher::class, $otherGroupTeacher);

        self::assertFalse($this->repository->existsForGroupTeacher($otherGroupTeacher));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} */
    private function makeWorld(string $suffix): array
    {
        $centre       = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'q'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year         = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course       = (new Course())->setName('DAW')->setAcademicYear($year);
        $group        = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE' . $suffix . uniqid('', false));
        $teacher = $this->makeTeacher($suffix);
        $group->addTeacher($teacher, 'Matemáticas');
        $groupTeacher = $group->getTeacherAssignments()->first();
        self::assertInstanceOf(GroupTeacher::class, $groupTeacher);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student, $teacher);

        return compact('centre', 'year', 'group', 'student', 'teacher', 'groupTeacher');
    }

    private function makeTeacher(string $suffix): Teacher
    {
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.' . $suffix . uniqid('', false));
        $this->persist($teacher);

        return $teacher;
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} $world */
    private function makeSanction(array $world, bool $requiresDates): Sanction
    {
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($world['teacher'])
            ->setDetails('Detalles de prueba')
            ->setNoMeasureApplied(!$requiresDates)
            ->setNoMeasureReason($requiresDates ? null : 'Sin medida')
            ->setEffectiveFrom($requiresDates ? new \DateTimeImmutable('+2 days') : null)
            ->setEffectiveTo($requiresDates ? new \DateTimeImmutable('+7 days') : null);

        if ($requiresDates) {
            $category = (new SanctionMeasureCategory())
                ->setEducationalCentre($world['centre'])
                ->setName('Correcciones')
                ->setPosition(0);
            $measure = (new SanctionMeasure())
                ->setEducationalCentre($world['centre'])
                ->setCategory($category)
                ->setName('Expulsión con actividades')
                ->setHasDateRange(true)
                ->setPosition(0)
                ->setActive(true);
            $this->persist($category, $measure);
            $sanction->addMeasure($measure);
        }

        $this->persist($sanction);

        return $sanction;
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} $world */
    private function makeReport(array $world, Sanction $sanction): IncidentReport
    {
        $category = (new IncidentBehaviorCategory())
            ->setEducationalCentre($world['centre'])
            ->setName('Contrarias')
            ->setSerious(false)
            ->setPosition(0);
        $behavior = (new IncidentBehavior())
            ->setEducationalCentre($world['centre'])
            ->setCategory($category)
            ->setName('Perturbación del normal desarrollo de las actividades')
            ->setPosition(0)
            ->setActive(true);
        $report = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(1)
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($world['teacher'])
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test.</p>')
            ->setExpelledFromClass(false)
            ->setSanction($sanction);
        $report->addBehavior($behavior);
        $this->persist($category, $behavior, $report);

        return $report;
    }
}
