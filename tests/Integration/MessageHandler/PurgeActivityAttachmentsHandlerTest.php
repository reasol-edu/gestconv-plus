<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Activity;
use App\Entity\ActivityAttachment;
use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Message\PurgeActivityAttachmentsMessage;
use App\MessageHandler\PurgeActivityAttachmentsHandler;
use App\Tests\Integration\RepositoryTestCase;

class PurgeActivityAttachmentsHandlerTest extends RepositoryTestCase
{
    private PurgeActivityAttachmentsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRetentionDefinition();

        /** @var PurgeActivityAttachmentsHandler $handler */
        $handler       = self::getContainer()->get(PurgeActivityAttachmentsHandler::class);
        $this->handler = $handler;
    }

    public function testPurgesAttachmentsOlderThanRetentionPeriod(): void
    {
        $world      = $this->makeWorld('old');
        $activity   = $this->makeActivity($world, new \DateTimeImmutable('-100 days'));
        $attachment = $this->makeAttachment($activity, 'ejercicio.pdf', 2048);
        $this->persist($activity, $attachment);
        $this->setRetentionDays($world['centre'], 90);
        $attachmentId = $attachment->getId();

        ($this->handler)(new PurgeActivityAttachmentsMessage());

        $this->em->clear();
        /** @var Activity $reloaded */
        $reloaded = $this->em->getRepository(Activity::class)->find($activity->getId());
        self::assertCount(0, $reloaded->getAttachments());
        self::assertNull($this->em->getRepository(ActivityAttachment::class)->find($attachmentId));
    }

    public function testAppendsTranslatedNoteToDescriptionAsHtml(): void
    {
        $world      = $this->makeWorld('note');
        $activity   = $this->makeActivity($world, new \DateTimeImmutable('-100 days'), '<p>Actividad de prueba.</p>');
        $attachment = $this->makeAttachment($activity, 'ejercicio.pdf', 2048);
        $this->persist($activity, $attachment);
        $this->setRetentionDays($world['centre'], 90);

        ($this->handler)(new PurgeActivityAttachmentsMessage());

        $this->em->clear();
        /** @var Activity $reloaded */
        $reloaded    = $this->em->getRepository(Activity::class)->find($activity->getId());
        $description = $reloaded->getDescription();

        self::assertStringContainsString('<p>Actividad de prueba.</p>', $description);
        self::assertStringContainsString('<p><em>', $description);
        self::assertStringContainsString('ejercicio.pdf', $description);
        self::assertStringContainsString('2 KB', $description);
        self::assertStringContainsString((new \DateTimeImmutable())->format('d/m/Y'), $description);
    }

    public function testDoesNotPurgeAttachmentWithinRetentionPeriod(): void
    {
        $world      = $this->makeWorld('recent');
        $activity   = $this->makeActivity($world, new \DateTimeImmutable('-10 days'));
        $attachment = $this->makeAttachment($activity, 'reciente.pdf', 512);
        $this->persist($activity, $attachment);
        $this->setRetentionDays($world['centre'], 90);

        ($this->handler)(new PurgeActivityAttachmentsMessage());

        $this->em->clear();
        /** @var Activity $reloaded */
        $reloaded = $this->em->getRepository(Activity::class)->find($activity->getId());
        self::assertCount(1, $reloaded->getAttachments());
    }

    public function testZeroDaysSettingDisablesPurgeForThatCentre(): void
    {
        $world      = $this->makeWorld('disabled');
        $activity   = $this->makeActivity($world, new \DateTimeImmutable('-1000 days'));
        $attachment = $this->makeAttachment($activity, 'antiguo.pdf', 512);
        $this->persist($activity, $attachment);
        $this->setRetentionDays($world['centre'], 0);

        ($this->handler)(new PurgeActivityAttachmentsMessage());

        $this->em->clear();
        /** @var Activity $reloaded */
        $reloaded = $this->em->getRepository(Activity::class)->find($activity->getId());
        self::assertCount(1, $reloaded->getAttachments());
    }

    public function testEachCentreUsesItsOwnThreshold(): void
    {
        $worldA      = $this->makeWorld('centreA');
        $worldB      = $this->makeWorld('centreB');
        $activityA   = $this->makeActivity($worldA, new \DateTimeImmutable('-10 days'));
        $activityB   = $this->makeActivity($worldB, new \DateTimeImmutable('-10 days'));
        $attachmentA = $this->makeAttachment($activityA, 'a.pdf', 100);
        $attachmentB = $this->makeAttachment($activityB, 'b.pdf', 100);
        $this->persist($activityA, $attachmentA, $activityB, $attachmentB);
        $this->setRetentionDays($worldA['centre'], 7);
        $this->setRetentionDays($worldB['centre'], 30);

        ($this->handler)(new PurgeActivityAttachmentsMessage());

        $this->em->clear();
        /** @var Activity $reloadedA */
        $reloadedA = $this->em->getRepository(Activity::class)->find($activityA->getId());
        /** @var Activity $reloadedB */
        $reloadedB = $this->em->getRepository(Activity::class)->find($activityB->getId());
        self::assertCount(0, $reloadedA->getAttachments());
        self::assertCount(1, $reloadedB->getAttachments());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear, teacher: Teacher, timeSlot: TimeSlot} */
    private function makeWorld(string $suffix): array
    {
        $centre   = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'z'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $teacher  = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.' . $suffix . uniqid('', false));
        $timeSlot = (new TimeSlot())
            ->setName('Tramo 1')
            ->setDayOfWeek(1)
            ->setStartTime(new \DateTimeImmutable('08:00'))
            ->setEndTime(new \DateTimeImmutable('09:00'))
            ->setAcademicYear($year);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $teacher, $timeSlot);

        return compact('centre', 'year', 'teacher', 'timeSlot');
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, teacher: Teacher, timeSlot: TimeSlot} $world */
    private function makeActivity(array $world, \DateTimeImmutable $date, string $description = '<p>Actividad.</p>'): Activity
    {
        $absence = (new Absence())
            ->setTeacher($world['teacher'])
            ->setAcademicYear($world['year'])
            ->setStartDate($date->modify('-1 day'))
            ->setEndDate($date->modify('+1 day'));
        $this->persist($absence);

        return (new Activity())
            ->setAbsence($absence)
            ->setDate($date)
            ->setTimeSlot($world['timeSlot'])
            ->setDescription($description);
    }

    private function makeAttachment(Activity $activity, string $filename, int $size): ActivityAttachment
    {
        $attachment = new ActivityAttachment($activity, $filename, 'application/pdf', $size, 'contenido');
        $activity->addAttachment($attachment);

        return $attachment;
    }

    private function seedRetentionDefinition(): void
    {
        $definition = (new SettingDefinition())
            ->setKey('absences.attachment_retention_days')
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
            ->findOneBy(['key' => 'absences.attachment_retention_days']);

        $value = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue((string) $days);
        $this->persist($value);
    }
}
