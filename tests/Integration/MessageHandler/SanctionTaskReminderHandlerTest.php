<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Message\SanctionTaskReminderMessage;
use App\MessageHandler\SanctionTaskReminderHandler;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Mime\RawMessage;

class SanctionTaskReminderHandlerTest extends RepositoryTestCase
{
    private SanctionTaskReminderHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReminderDefinition();

        /** @var SanctionTaskReminderHandler $handler */
        $handler       = self::getContainer()->get(SanctionTaskReminderHandler::class);
        $this->handler = $handler;
    }

    public function testSendsReminderEmailWhenSanctionStartsWithinThreshold(): void
    {
        $world = $this->makeWorld('within');
        $this->makeTask($world, new \DateTimeImmutable('+2 days'));
        $this->setReminderDays($world['centre'], 3);

        ($this->handler)(new SanctionTaskReminderMessage());

        self::assertEmailCount(1);
    }

    public function testDoesNotSendReminderWhenBeyondThreshold(): void
    {
        $world = $this->makeWorld('beyond');
        $this->makeTask($world, new \DateTimeImmutable('+10 days'));
        $this->setReminderDays($world['centre'], 3);

        ($this->handler)(new SanctionTaskReminderMessage());

        self::assertEmailCount(0);
    }

    public function testZeroReminderDaysDisablesReminderForThatCentre(): void
    {
        $world = $this->makeWorld('zero');
        $this->makeTask($world, new \DateTimeImmutable('+1 day'));
        $this->setReminderDays($world['centre'], 0);

        ($this->handler)(new SanctionTaskReminderMessage());

        self::assertEmailCount(0);
    }

    public function testExcludesAlreadyCompletedTasks(): void
    {
        $world = $this->makeWorld('completed');
        $task  = $this->makeTask($world, new \DateTimeImmutable('+2 days'));
        $task->setCompletedAt(new \DateTimeImmutable());
        $this->flush();
        $this->setReminderDays($world['centre'], 3);

        ($this->handler)(new SanctionTaskReminderMessage());

        self::assertEmailCount(0);
    }

    public function testSendsSingleDigestEmailForMultipleTasksOfSameTeacher(): void
    {
        $world = $this->makeWorld('digest');
        $this->makeTask($world, new \DateTimeImmutable('+1 day'));
        $this->makeTask($world, new \DateTimeImmutable('+2 days'));
        $this->setReminderDays($world['centre'], 3);

        ($this->handler)(new SanctionTaskReminderMessage());

        self::assertEmailCount(1);
        self::assertEmailSubjectContains($this->sentMessage(0), '2');
    }

    public function testEachCentreUsesItsOwnThreshold(): void
    {
        $worldA = $this->makeWorld('centreA');
        $worldB = $this->makeWorld('centreB');
        $this->makeTask($worldA, new \DateTimeImmutable('+5 days'));
        $this->makeTask($worldB, new \DateTimeImmutable('+5 days'));
        $this->setReminderDays($worldA['centre'], 1);
        $this->setReminderDays($worldB['centre'], 10);

        ($this->handler)(new SanctionTaskReminderMessage());

        self::assertEmailCount(1);
        self::assertEmailAddressContains($this->sentMessage(0), 'To', $worldB['teacher']->getEmail());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} */
    private function makeWorld(string $suffix): array
    {
        $centre       = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'r'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year         = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course       = (new Course())->setName('DAW')->setAcademicYear($year);
        $group        = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student      = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE' . $suffix . uniqid('', false));
        $teacher      = (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername('teacher.' . $suffix . uniqid('', false))
            ->setEmail('teacher.' . $suffix . '@ejemplo.local');
        $groupTeacher = new GroupTeacher($group, $teacher, 'Matemáticas');

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student, $teacher, $groupTeacher);

        return compact('centre', 'year', 'group', 'student', 'teacher', 'groupTeacher');
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} $world */
    private function makeTask(array $world, \DateTimeImmutable $effectiveFrom): SanctionTask
    {
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($world['teacher'])
            ->setDetails('Detalles de prueba')
            ->setNoMeasureApplied(false)
            ->setEffectiveFrom($effectiveFrom)
            ->setEffectiveTo($effectiveFrom->modify('+5 days'));
        $this->persist($sanction);

        $task = new SanctionTask($sanction, $world['groupTeacher']);
        $this->persist($task);

        return $task;
    }

    private function seedReminderDefinition(): void
    {
        $definition = (new SettingDefinition())
            ->setKey('notifications.sanction_task_reminder_days')
            ->setType(SettingType::Integer)
            ->setDefaultValue('3')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setMinValue(0)
            ->setMaxValue(365);
        $this->persist($definition);
    }

    private function setReminderDays(EducationalCentre $centre, int $days): void
    {
        /** @var SettingDefinition $definition */
        $definition = $this->em->getRepository(SettingDefinition::class)
            ->findOneBy(['key' => 'notifications.sanction_task_reminder_days']);

        $value = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue((string) $days);
        $this->persist($value);
    }

    /**
     * El bus de Messenger en entorno de test entrega SendEmailMessage de forma
     * síncrona, por lo que cada envío produce dos MessageEvent (uno "queued" y
     * otro real); getMailerMessage() no filtra por eso, así que se descartan
     * aquí los eventos en cola para quedarnos solo con los correos enviados.
     */
    private function sentMessage(int $index): RawMessage
    {
        $sent = array_values(array_filter(
            self::getMailerEvents(),
            static fn ($event) => !$event->isQueued(),
        ));

        return $sent[$index]->getMessage();
    }
}
