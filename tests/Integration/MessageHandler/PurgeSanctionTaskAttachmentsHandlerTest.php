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
use App\Entity\SanctionTaskAttachment;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Message\PurgeSanctionTaskAttachmentsMessage;
use App\MessageHandler\PurgeSanctionTaskAttachmentsHandler;
use App\Tests\Integration\RepositoryTestCase;

class PurgeSanctionTaskAttachmentsHandlerTest extends RepositoryTestCase
{
    private PurgeSanctionTaskAttachmentsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRetentionDefinition();

        /** @var PurgeSanctionTaskAttachmentsHandler $handler */
        $handler       = self::getContainer()->get(PurgeSanctionTaskAttachmentsHandler::class);
        $this->handler = $handler;
    }

    public function testPurgesAttachmentsOfSanctionsClosedBeyondRetentionPeriod(): void
    {
        $world        = $this->makeWorld('old');
        $task         = $this->makeTask($world, new \DateTimeImmutable('-100 days'));
        $attachment   = $this->makeAttachment($task, 'trabajo.pdf', 2048);
        $this->persist($task, $attachment);
        $this->setRetentionDays($world['centre'], 90);
        $attachmentId = $attachment->getId();

        ($this->handler)(new PurgeSanctionTaskAttachmentsMessage());

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded = $this->em->getRepository(SanctionTask::class)->find($task->getId());
        self::assertCount(0, $reloaded->getAttachments());
        self::assertNull($this->em->getRepository(SanctionTaskAttachment::class)->find($attachmentId));
    }

    public function testAppendsTranslatedNoteToDescriptionAsHtml(): void
    {
        $world      = $this->makeWorld('note');
        $task       = $this->makeTask($world, new \DateTimeImmutable('-100 days'), '<p>Trabajo de prueba.</p>');
        $attachment = $this->makeAttachment($task, 'trabajo.pdf', 2048);
        $this->persist($task, $attachment);
        $this->setRetentionDays($world['centre'], 90);

        ($this->handler)(new PurgeSanctionTaskAttachmentsMessage());

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded    = $this->em->getRepository(SanctionTask::class)->find($task->getId());
        $description = $reloaded->getDescription();

        self::assertStringContainsString('<p>Trabajo de prueba.</p>', $description);
        self::assertStringContainsString('<p><em>', $description);
        self::assertStringContainsString('trabajo.pdf', $description);
        self::assertStringContainsString('2 KB', $description);
        self::assertStringContainsString((new \DateTimeImmutable())->format('d/m/Y'), $description);
    }

    public function testDoesNotPurgeAttachmentOfSanctionWithinRetentionPeriod(): void
    {
        $world      = $this->makeWorld('recent');
        $task       = $this->makeTask($world, new \DateTimeImmutable('-10 days'));
        $attachment = $this->makeAttachment($task, 'reciente.pdf', 512);
        $this->persist($task, $attachment);
        $this->setRetentionDays($world['centre'], 90);

        ($this->handler)(new PurgeSanctionTaskAttachmentsMessage());

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded = $this->em->getRepository(SanctionTask::class)->find($task->getId());
        self::assertCount(1, $reloaded->getAttachments());
    }

    public function testZeroDaysSettingDisablesPurgeForThatCentre(): void
    {
        $world      = $this->makeWorld('disabled');
        $task       = $this->makeTask($world, new \DateTimeImmutable('-1000 days'));
        $attachment = $this->makeAttachment($task, 'antiguo.pdf', 512);
        $this->persist($task, $attachment);
        $this->setRetentionDays($world['centre'], 0);

        ($this->handler)(new PurgeSanctionTaskAttachmentsMessage());

        $this->em->clear();
        /** @var SanctionTask $reloaded */
        $reloaded = $this->em->getRepository(SanctionTask::class)->find($task->getId());
        self::assertCount(1, $reloaded->getAttachments());
    }

    public function testEachCentreUsesItsOwnThreshold(): void
    {
        $worldA      = $this->makeWorld('centreA');
        $worldB      = $this->makeWorld('centreB');
        $taskA       = $this->makeTask($worldA, new \DateTimeImmutable('-10 days'));
        $taskB       = $this->makeTask($worldB, new \DateTimeImmutable('-10 days'));
        $attachmentA = $this->makeAttachment($taskA, 'a.pdf', 100);
        $attachmentB = $this->makeAttachment($taskB, 'b.pdf', 100);
        $this->persist($taskA, $attachmentA, $taskB, $attachmentB);
        $this->setRetentionDays($worldA['centre'], 7);
        $this->setRetentionDays($worldB['centre'], 30);

        ($this->handler)(new PurgeSanctionTaskAttachmentsMessage());

        $this->em->clear();
        /** @var SanctionTask $reloadedA */
        $reloadedA = $this->em->getRepository(SanctionTask::class)->find($taskA->getId());
        /** @var SanctionTask $reloadedB */
        $reloadedB = $this->em->getRepository(SanctionTask::class)->find($taskB->getId());
        self::assertCount(0, $reloadedA->getAttachments());
        self::assertCount(1, $reloadedB->getAttachments());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} */
    private function makeWorld(string $suffix): array
    {
        $centre       = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'p'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year         = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course       = (new Course())->setName('DAW')->setAcademicYear($year);
        $group        = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student      = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE' . $suffix . uniqid('', false));
        $teacher      = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.' . $suffix . uniqid('', false));
        $groupTeacher = new GroupTeacher($group, $teacher, 'Matemáticas');

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student, $teacher, $groupTeacher);

        return compact('centre', 'year', 'group', 'student', 'teacher', 'groupTeacher');
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} $world */
    private function makeTask(array $world, \DateTimeImmutable $effectiveTo, string $description = '<p>Trabajo.</p>'): SanctionTask
    {
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($world['teacher'])
            ->setDetails('Detalles de prueba')
            ->setNoMeasureApplied(false)
            ->setEffectiveFrom($effectiveTo->modify('-5 days'))
            ->setEffectiveTo($effectiveTo);
        $this->persist($sanction);

        $task = new SanctionTask($sanction, $world['groupTeacher']);
        $task->setDescription($description);

        return $task;
    }

    private function makeAttachment(SanctionTask $task, string $filename, int $size): SanctionTaskAttachment
    {
        $attachment = new SanctionTaskAttachment($task, $filename, 'application/pdf', $size, 'contenido');
        $task->addAttachment($attachment);

        return $attachment;
    }

    private function seedRetentionDefinition(): void
    {
        $definition = (new SettingDefinition())
            ->setKey('sanction_tasks.attachment_retention_days')
            ->setType(SettingType::Integer)
            ->setDefaultValue('90')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setMinValue(0)
            ->setMaxValue(3650);
        $this->persist($definition);
    }

    private function setRetentionDays(EducationalCentre $centre, int $days): void
    {
        /** @var SettingDefinition $definition */
        $definition = $this->em->getRepository(SettingDefinition::class)
            ->findOneBy(['key' => 'sanction_tasks.attachment_retention_days']);

        $value = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue((string) $days);
        $this->persist($value);
    }
}
