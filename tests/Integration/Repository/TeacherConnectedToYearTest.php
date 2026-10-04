<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Repository\TeacherRepository;
use App\Tests\Integration\RepositoryTestCase;

/** Vinculaciones de un docente con un curso académico (base para retirar docentes sin actividad). */
class TeacherConnectedToYearTest extends RepositoryTestCase
{
    public function testEveryKindOfLinkCountsAndOtherYearsDoNot(): void
    {
        $centre = (new EducationalCentre())->setCode('41000003')->setName('IES')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $old    = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);

        $names = [
            'tutor', 'teacher', 'guard', 'absent', 'reporter', 'sanctioner', 'noter',
            'cadmin', 'committee', 'counselor', 'free',
            'oldtutor', 'oldteacher', 'oldguard', 'oldabsent', 'oldreporter',
        ];
        $t = [];
        foreach ($names as $n) {
            $t[$n] = (new Teacher(new PersonName($n, 'X')))->setUsername($n);
        }

        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE1');
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1A')->setCourse($course)->addTutor($t['tutor'])->addTeacher($t['teacher'], 'Matemáticas');

        // Vinculados solo en un curso anterior: no cuentan para el actual
        $oldCourse = (new Course())->setName('DAW')->setAcademicYear($old);
        $oldGroup  = (new Group())->setName('1A')->setCourse($oldCourse)->addTutor($t['oldtutor'])->addTeacher($t['oldteacher'], 'Lengua');

        $centre->addAdmin($t['cadmin']);
        $centre->addCommitteeMember($t['committee']);
        $centre->addCounselor($t['counselor']);

        $slot    = $this->slot($year)->addGuard($t['guard']);
        $oldSlot = $this->slot($old)->addGuard($t['oldguard']);

        $absence    = (new Absence())->setTeacher($t['absent'])->setAcademicYear($year)
            ->setStartDate(new \DateTimeImmutable('2026-03-01'))->setEndDate(new \DateTimeImmutable('2026-03-02'));
        $oldAbsence = (new Absence())->setTeacher($t['oldabsent'])->setAcademicYear($old)
            ->setStartDate(new \DateTimeImmutable('2025-03-01'))->setEndDate(new \DateTimeImmutable('2025-03-02'));

        $noteType = (new DailyNoteType())->setEducationalCentre($centre)->setName('Tipo')->setPosition(0);

        $this->persist(
            $centre, $year, $old, ...array_values($t),
            ...[$student, $course, $group, $oldCourse, $oldGroup, $slot, $oldSlot, $absence, $oldAbsence, $noteType],
        );

        $report = (new IncidentReport())->setAcademicYear($year)->setNumber(1)->setStudent($student)->setGroup($group)
            ->setRegisteredBy($t['reporter'])->setOccurredAt(new \DateTimeImmutable('2026-03-01'))
            ->setDescription('<p>Test</p>')->setExpelledFromClass(false);
        $oldReport = (new IncidentReport())->setAcademicYear($old)->setNumber(1)->setStudent($student)->setGroup($oldGroup)
            ->setRegisteredBy($t['oldreporter'])->setOccurredAt(new \DateTimeImmutable('2025-03-01'))
            ->setDescription('<p>Test</p>')->setExpelledFromClass(false);
        $sanction = (new Sanction())->setAcademicYear($year)->setStudent($student)->setGroup($group)
            ->setRegisteredBy($t['sanctioner'])->setDetails('Detalle')->setNoMeasureApplied(false);
        $note = (new DailyNote())->setAcademicYear($year)->setStudent($student)->setGroup($group)
            ->setType($noteType)->setRegisteredBy($t['noter'])->setActive(true);
        $this->persist($report, $oldReport, $sanction, $note);

        /** @var TeacherRepository $repo */
        $repo      = self::getContainer()->get(TeacherRepository::class);
        $byId      = array_map(static fn (Teacher $x): string => $x->getId()->toRfc4122(), $t);
        $usernames = array_map(
            static fn (string $id): string => (string) array_search($id, $byId, true),
            array_keys($repo->findConnectedIdsForYear($year)),
        );
        sort($usernames);

        self::assertSame(
            ['absent', 'cadmin', 'committee', 'counselor', 'guard', 'noter', 'reporter', 'sanctioner', 'teacher', 'tutor'],
            $usernames,
        );
        foreach (['free', 'oldtutor', 'oldteacher', 'oldguard', 'oldabsent', 'oldreporter'] as $notConnected) {
            self::assertNotContains($notConnected, $usernames);
        }
    }

    private function slot(AcademicYear $year): TimeSlot
    {
        return (new TimeSlot())
            ->setAcademicYear($year)
            ->setName('1ª hora')
            ->setDayOfWeek(1)
            ->setStartTime(new \DateTimeImmutable('2000-01-01 08:00'))
            ->setEndTime(new \DateTimeImmutable('2000-01-01 08:55'));
    }
}
