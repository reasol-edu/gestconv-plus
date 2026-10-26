<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\TeacherReplacement;
use App\Tests\Integration\RepositoryTestCase;

class TeacherReplacementTest extends RepositoryTestCase
{
    public function testItCopiesEveryProfileTutorshipAndSubjectAndNothingTwice(): void
    {
        $centre = (new EducationalCentre())->setCode('43555555')->setName('IES Sustitución')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course = (new Course())->setName('1º ESO')->setAcademicYear($year);
        $groupA = (new Group())->setName('1ºA')->setCourse($course);
        $groupB = (new Group())->setName('1ºB')->setCourse($course);
        $old    = (new Teacher(new PersonName('Ana', 'Antigua')))->setUsername('antigua');
        $new    = (new Teacher(new PersonName('Nico', 'Nuevo')))->setUsername('nuevo');
        $this->persist($centre, $year, $course, $groupA, $groupB, $old, $new);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($old);
        $year->addTeacher($new);
        $centre->addAdmin($old);
        $centre->addCommitteeMember($old);
        $centre->addCounselor($old);
        $centre->addActivityCoordinator($old);
        $groupA->addTutor($old);
        $groupA->addTeacher($old, 'Matemáticas');
        $groupA->addTeacher($old, 'Tutoría');
        $groupB->addTeacher($old, 'Física');
        $slot  = (new \App\Entity\TimeSlot())->setName('1ª')->setDayOfWeek(0)->setAcademicYear($year)
            ->setStartTime(new \DateTimeImmutable('08:00'))->setEndTime(new \DateTimeImmutable('09:00'));
        $slot2 = (new \App\Entity\TimeSlot())->setName('2ª')->setDayOfWeek(0)->setAcademicYear($year)
            ->setStartTime(new \DateTimeImmutable('09:00'))->setEndTime(new \DateTimeImmutable('10:00'));
        $this->persist($slot, $slot2);
        $slot->addGuard($old);
        $this->flush();

        /** @var TeacherReplacement $service */
        $service = self::getContainer()->get(TeacherReplacement::class);

        self::assertSame(['profiles' => 4, 'tutorships' => 1, 'subjects' => 3, 'guards' => 1], $service->copyAssignments($old, $new, $year));
        $this->flush();
        self::assertTrue($slot->getGuards()->contains($new), 'guardia copiada');
        self::assertFalse($slot2->getGuards()->contains($new), 'solo donde el sustituido tenía guardia');
        self::assertTrue($centre->getAdmins()->contains($new));
        self::assertTrue($centre->getActivityCoordinators()->contains($new));
        self::assertTrue($groupA->getTutors()->contains($new));
        self::assertTrue($groupB->hasTeacherSubject($new, 'Física'));

        // Repetirlo no duplica nada; y a uno mismo no se copia nada.
        self::assertSame(['profiles' => 0, 'tutorships' => 0, 'subjects' => 0, 'guards' => 0], $service->copyAssignments($old, $new, $year));
        self::assertSame(['profiles' => 0, 'tutorships' => 0, 'subjects' => 0, 'guards' => 0], $service->copyAssignments($old, $old, $year));
    }
}
