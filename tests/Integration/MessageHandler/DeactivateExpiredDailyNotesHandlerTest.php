<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Message\DeactivateExpiredDailyNotesMessage;
use App\MessageHandler\DeactivateExpiredDailyNotesHandler;
use App\Tests\Integration\RepositoryTestCase;

class DeactivateExpiredDailyNotesHandlerTest extends RepositoryTestCase
{
    private DeactivateExpiredDailyNotesHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DeactivateExpiredDailyNotesHandler $handler */
        $handler       = self::getContainer()->get(DeactivateExpiredDailyNotesHandler::class);
        $this->handler = $handler;
    }

    public function testDeactivatesNotesOlderThanExpiryDays(): void
    {
        $world = $this->makeWorld('expired', expiryDays: 30);
        $old   = $this->makeNote($world, new \DateTimeImmutable('-40 days'));
        $this->persist($old);

        ($this->handler)(new DeactivateExpiredDailyNotesMessage());

        $this->em->clear();
        /** @var DailyNote $reloaded */
        $reloaded = $this->em->find(DailyNote::class, $old->getId());
        self::assertFalse($reloaded->isActive());
    }

    public function testKeepsRecentNotesActive(): void
    {
        $world  = $this->makeWorld('recent', expiryDays: 30);
        $recent = $this->makeNote($world, new \DateTimeImmutable('-5 days'));
        $this->persist($recent);

        ($this->handler)(new DeactivateExpiredDailyNotesMessage());

        $this->em->clear();
        /** @var DailyNote $reloaded */
        $reloaded = $this->em->find(DailyNote::class, $recent->getId());
        self::assertTrue($reloaded->isActive());
    }

    public function testZeroExpiryDaysDisablesDeactivation(): void
    {
        $world = $this->makeWorld('never', expiryDays: 0);
        $old   = $this->makeNote($world, new \DateTimeImmutable('-1000 days'));
        $this->persist($old);

        ($this->handler)(new DeactivateExpiredDailyNotesMessage());

        $this->em->clear();
        /** @var DailyNote $reloaded */
        $reloaded = $this->em->find(DailyNote::class, $old->getId());
        self::assertTrue($reloaded->isActive());
    }

    public function testAlreadyInactiveNoteIsNotTouched(): void
    {
        $world = $this->makeWorld('already-inactive', expiryDays: 30);
        $old   = $this->makeNote($world, new \DateTimeImmutable('-40 days'));
        $old->setActive(false);
        $this->persist($old);

        ($this->handler)(new DeactivateExpiredDailyNotesMessage());

        $this->em->clear();
        /** @var DailyNote $reloaded */
        $reloaded = $this->em->find(DailyNote::class, $old->getId());
        self::assertFalse($reloaded->isActive());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, type: DailyNoteType} */
    private function makeWorld(string $suffix, int $expiryDays): array
    {
        $centre  = (new EducationalCentre())->setCode('41' . substr(md5($suffix . 'ded'), 0, 6))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.ded.' . $suffix . uniqid('', false));
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-ded-' . uniqid('', false));
        $student->addGroup($group);
        $type = (new DailyNoteType())->setEducationalCentre($centre)->setName('Tipo ' . $suffix)->setExpiryDays($expiryDays)->setPosition(0);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $teacher, $student, $type);

        return compact('centre', 'year', 'group', 'student', 'teacher', 'type');
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, type: DailyNoteType} $world */
    private function makeNote(array $world, \DateTimeImmutable $occurredAt): DailyNote
    {
        return (new DailyNote())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setType($world['type'])
            ->setRegisteredBy($world['teacher'])
            ->setOccurredAt($occurredAt);
    }
}
