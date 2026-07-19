<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Service\StudentContactVisibility;
use PHPUnit\Framework\TestCase;

class StudentContactVisibilityTest extends TestCase
{
    private StudentContactVisibility $visibility;

    protected function setUp(): void
    {
        $this->visibility = new StudentContactVisibility();
    }

    public function testCanEditContactTrueForTutorOfStudentGroupInGivenYear(): void
    {
        [$year, $group, $student] = $this->makeChain();
        $tutor = $this->makeTeacher();
        $group->addTutor($tutor);

        self::assertTrue($this->visibility->canEditContact($tutor, $student, $year));
    }

    public function testCanEditContactFalseForNonTutor(): void
    {
        [$year, $group, $student] = $this->makeChain();
        $other = $this->makeTeacher();
        $group->addTeacher($other, 'Matemáticas');

        self::assertFalse($this->visibility->canEditContact($other, $student, $year));
    }

    public function testCanEditContactFalseForCentreAdmin(): void
    {
        [$year, , $student] = $this->makeChain();
        $admin = $this->makeTeacher();
        $admin->setAdmin(true);

        self::assertFalse($this->visibility->canEditContact($admin, $student, $year));
    }

    public function testCanEditContactFalseWhenTutorButDifferentAcademicYear(): void
    {
        [, $group, $student] = $this->makeChain();
        $tutor = $this->makeTeacher();
        $group->addTutor($tutor);

        $centre    = $group->getAcademicYear()->getEducationalCentre();
        $otherYear = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);

        self::assertFalse($this->visibility->canEditContact($tutor, $student, $otherYear));
    }

    /** @return array{0: AcademicYear, 1: Group, 2: Student} */
    private function makeChain(): array
    {
        $centre  = (new EducationalCentre())->setCode('41000001')->setName('IES Test')->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $student = new Student(new PersonName('Ana', 'García'));
        $student->addGroup($group);

        return [$year, $group, $student];
    }

    private function makeTeacher(): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.' . uniqid('', false));
    }
}
