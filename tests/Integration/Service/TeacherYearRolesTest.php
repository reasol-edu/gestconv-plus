<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Repository\TeacherRepository;
use App\Service\TeacherYearRoles;
use App\Tests\Integration\RepositoryTestCase;

class TeacherYearRolesTest extends RepositoryTestCase
{
    private TeacherRepository $repo;
    private TeacherYearRoles $roles;
    private AcademicYear $year;
    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var TeacherRepository $repo */
        $repo        = self::getContainer()->get(TeacherRepository::class);
        $this->repo  = $repo;
        /** @var TeacherYearRoles $roles */
        $roles       = self::getContainer()->get(TeacherYearRoles::class);
        $this->roles = $roles;

        $centre      = (new EducationalCentre())->setCode('43000010')->setName('IES Roles')->setCity('Sevilla');
        $this->year  = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course      = (new Course())->setName('DAW')->setAcademicYear($this->year);
        $this->group = (new Group())->setName('1ºA')->setCourse($course);
        $this->persist($centre, $this->year, $course, $this->group);
    }

    public function testTeacherWithoutAnyRoleHasNone(): void
    {
        $teacher = $this->makeTeacher('ninguno');

        self::assertSame(
            ['tutors' => false, 'teaches' => false, 'guards' => false, 'member' => false],
            $this->repo->findYearRoles($teacher, $this->year),
        );
    }

    public function testTutorTeachesAndIsMemberOnlyWhenAssigned(): void
    {
        $tutor = $this->makeTeacher('tutor');
        $this->group->addTutor($tutor);
        $this->year->addTeacher($tutor);
        $this->flush();

        self::assertSame(
            ['tutors' => true, 'teaches' => true, 'guards' => false, 'member' => true],
            $this->repo->findYearRoles($tutor, $this->year),
        );
    }

    public function testTeacherOfAGroupDoesNotTutor(): void
    {
        $teacher = $this->makeTeacher('docente');
        $this->group->addTeacher($teacher, 'Matemáticas');
        $this->flush();

        self::assertSame(
            ['tutors' => false, 'teaches' => true, 'guards' => false, 'member' => false],
            $this->repo->findYearRoles($teacher, $this->year),
        );
    }

    public function testGuardOnlyCountsSlotsOfTheGivenYear(): void
    {
        $guard = $this->makeTeacher('guardia');
        $slot  = (new TimeSlot())
            ->setAcademicYear($this->year)
            ->setName('1ª hora')
            ->setDayOfWeek(0)
            ->setStartTime(new \DateTimeImmutable('08:00'))
            ->setEndTime(new \DateTimeImmutable('08:55'));
        $slot->addGuard($guard);
        $this->persist($slot);

        $otherYear = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($this->year->getEducationalCentre());
        $this->persist($otherYear);

        self::assertTrue($this->repo->findYearRoles($guard, $this->year)['guards']);
        self::assertFalse($this->repo->findYearRoles($guard, $otherYear)['guards']);
    }

    public function testServiceRemembersTheAnswerUntilTheNextFlush(): void
    {
        $teacher = $this->makeTeacher('memo');
        self::assertFalse($this->roles->tutorsAnyGroup($teacher, $this->year));

        $this->group->addTutor($teacher);
        $this->flush();

        self::assertTrue($this->roles->tutorsAnyGroup($teacher, $this->year), 'El flush debe invalidar lo recordado.');
    }

    private function makeTeacher(string $suffix): Teacher
    {
        $teacher = (new Teacher(new PersonName('Test', 'Roles')))->setUsername('roles.' . $suffix . uniqid('', false));
        $this->persist($teacher);

        return $teacher;
    }
}
