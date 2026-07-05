<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Entity\TeacherSettingValue;
use App\MessageHandler\WarnUpcomingReportPrescriptionsHandler;
use App\Message\WarnUpcomingReportPrescriptionsMessage;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Mime\RawMessage;

class WarnUpcomingReportPrescriptionsHandlerTest extends RepositoryTestCase
{
    private WarnUpcomingReportPrescriptionsHandler $handler;
    private int $nextReportNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDefinitions();

        /** @var WarnUpcomingReportPrescriptionsHandler $handler */
        $handler       = self::getContainer()->get(WarnUpcomingReportPrescriptionsHandler::class);
        $this->handler = $handler;
    }

    public function testSendsWarningEmailWhenWithinThreshold(): void
    {
        $world = $this->makeWorld('within');
        $this->makeReport($world, occurredAt: new \DateTimeImmutable('-12 days'));
        $this->setAutoPrescribeDays($world['centre'], 14);
        $this->setWarningDays($world['centre'], 7);

        ($this->handler)(new WarnUpcomingReportPrescriptionsMessage());

        self::assertEmailCount(1);
    }

    public function testDoesNotSendWarningWhenBeyondThreshold(): void
    {
        $world = $this->makeWorld('beyond');
        $this->makeReport($world, occurredAt: new \DateTimeImmutable('-2 days'));
        $this->setAutoPrescribeDays($world['centre'], 14);
        $this->setWarningDays($world['centre'], 7);

        ($this->handler)(new WarnUpcomingReportPrescriptionsMessage());

        self::assertEmailCount(0);
    }

    public function testZeroWarningDaysDisablesWarningForThatCentre(): void
    {
        $world = $this->makeWorld('zero');
        $this->makeReport($world, occurredAt: new \DateTimeImmutable('-13 days'));
        $this->setAutoPrescribeDays($world['centre'], 14);
        $this->setWarningDays($world['centre'], 0);

        ($this->handler)(new WarnUpcomingReportPrescriptionsMessage());

        self::assertEmailCount(0);
    }

    public function testTeacherLevelWarningDaysOverridesCentreDefault(): void
    {
        $world = $this->makeWorld('override');
        $this->makeReport($world, occurredAt: new \DateTimeImmutable('-12 days'));
        $this->setAutoPrescribeDays($world['centre'], 14);
        $this->setWarningDays($world['centre'], 1);
        $this->setTeacherWarningDays($world['creator'], 7);

        ($this->handler)(new WarnUpcomingReportPrescriptionsMessage());

        self::assertEmailCount(1);
    }

    public function testExcludesAlreadyPrescribedReports(): void
    {
        $world  = $this->makeWorld('prescribed');
        $report = $this->makeReport($world, occurredAt: new \DateTimeImmutable('-13 days'));
        $report->setPrescribedAt(new \DateTimeImmutable());
        $this->flush();
        $this->setAutoPrescribeDays($world['centre'], 14);
        $this->setWarningDays($world['centre'], 7);

        ($this->handler)(new WarnUpcomingReportPrescriptionsMessage());

        self::assertEmailCount(0);
    }

    public function testExcludesAlreadyNotifiedReports(): void
    {
        $world  = $this->makeWorld('notified');
        $report = $this->makeReport($world, occurredAt: new \DateTimeImmutable('-13 days'));
        $this->notify($report, $world, $world['creator']);
        $this->setAutoPrescribeDays($world['centre'], 14);
        $this->setWarningDays($world['centre'], 7);

        ($this->handler)(new WarnUpcomingReportPrescriptionsMessage());

        self::assertEmailCount(0);
    }

    public function testSendsSingleDigestEmailForMultipleQualifyingReports(): void
    {
        $world = $this->makeWorld('digest');
        $this->makeReport($world, occurredAt: new \DateTimeImmutable('-12 days'));
        $this->makeReport($world, occurredAt: new \DateTimeImmutable('-13 days'));
        $this->setAutoPrescribeDays($world['centre'], 14);
        $this->setWarningDays($world['centre'], 7);

        ($this->handler)(new WarnUpcomingReportPrescriptionsMessage());

        self::assertEmailCount(1);
        self::assertEmailSubjectContains($this->sentMessage(0), '2');
    }

    public function testRecipientsFollowReportTeacherNotifierSetting(): void
    {
        $world = $this->makeWorld('tutorOnly');
        $tutor = (new Teacher(new PersonName('Tutor', 'García')))
            ->setUsername('tutor.tutorOnly' . uniqid('', false))
            ->setEmail('tutor.tutorOnly@ejemplo.local');
        $this->persist($tutor);
        $world['group']->addTutor($tutor);
        $this->flush();

        $this->makeReport($world, occurredAt: new \DateTimeImmutable('-12 days'));
        $this->setAutoPrescribeDays($world['centre'], 14);
        $this->setWarningDays($world['centre'], 7);
        $this->setNotifierSetting($world['centre'], 'group_tutor');

        ($this->handler)(new WarnUpcomingReportPrescriptionsMessage());

        self::assertEmailCount(1);
        self::assertEmailAddressContains($this->sentMessage(0), 'To', 'tutor.tutorOnly@ejemplo.local');
    }

    public function testNotifierSettingBothSendsToCreatorAndTutor(): void
    {
        $world = $this->makeWorld('both');
        $tutor = (new Teacher(new PersonName('Tutor', 'García')))
            ->setUsername('tutor.both' . uniqid('', false))
            ->setEmail('tutor.both@ejemplo.local');
        $this->persist($tutor);
        $world['group']->addTutor($tutor);
        $this->flush();

        $this->makeReport($world, occurredAt: new \DateTimeImmutable('-12 days'));
        $this->setAutoPrescribeDays($world['centre'], 14);
        $this->setWarningDays($world['centre'], 7);
        $this->setNotifierSetting($world['centre'], 'both');

        ($this->handler)(new WarnUpcomingReportPrescriptionsMessage());

        self::assertEmailCount(2);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, creator: Teacher}
     */
    private function makeWorld(string $suffix): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'y'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA' . $suffix)->setProgrammeYear($level);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . $suffix . uniqid('', false));
        $creator   = (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername('creator.' . $suffix . uniqid('', false))
            ->setEmail('creator.' . $suffix . '@ejemplo.local');

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $programme, $level, $group, $student, $creator);

        return compact('centre', 'year', 'group', 'student', 'creator');
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, creator: Teacher} $world
     */
    private function makeReport(array $world, \DateTimeImmutable $occurredAt): IncidentReport
    {
        $report = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($world['creator'])
            ->setOccurredAt($occurredAt)
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false);
        $this->persist($report);

        return $report;
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, creator: Teacher} $world
     */
    private function notify(IncidentReport $report, array $world, Teacher $teacher): void
    {
        $method = (new CommunicationMethod())
            ->setEducationalCentre($world['centre'])
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($method);

        $communication = Communication::forIncidentReport($report, $method, $teacher, new \DateTimeImmutable(), CommunicationResult::Notified);
        $this->persist($communication);

        $report->setNotifiedCommunication($communication);
        $this->flush();
    }

    private function seedDefinitions(): void
    {
        $autoPrescribe = (new SettingDefinition())
            ->setKey('notifications.report_auto_prescribe_days')
            ->setType(SettingType::Integer)
            ->setDefaultValue('14')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setMinValue(0)
            ->setMaxValue(365);

        $warning = (new SettingDefinition())
            ->setKey('notifications.report_prescription_warning_days')
            ->setType(SettingType::Integer)
            ->setDefaultValue('7')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setTeacherScope(true)
            ->setMinValue(0)
            ->setMaxValue(365);

        $notifier = (new SettingDefinition())
            ->setKey('notifications.report_notifier')
            ->setType(SettingType::Choice)
            ->setDefaultValue('report_teacher')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setChoices('report_teacher,group_tutor,both');

        $this->persist($autoPrescribe, $warning, $notifier);
    }

    private function setAutoPrescribeDays(EducationalCentre $centre, int $days): void
    {
        $this->setCentreIntegerSetting('notifications.report_auto_prescribe_days', $centre, $days);
    }

    private function setWarningDays(EducationalCentre $centre, int $days): void
    {
        $this->setCentreIntegerSetting('notifications.report_prescription_warning_days', $centre, $days);
    }

    private function setCentreIntegerSetting(string $key, EducationalCentre $centre, int $value): void
    {
        /** @var SettingDefinition $definition */
        $definition = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => $key]);

        $centreValue = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue((string) $value);
        $this->persist($centreValue);
    }

    private function setTeacherWarningDays(Teacher $teacher, int $days): void
    {
        /** @var SettingDefinition $definition */
        $definition = $this->em->getRepository(SettingDefinition::class)
            ->findOneBy(['key' => 'notifications.report_prescription_warning_days']);

        $teacherValue = (new TeacherSettingValue())
            ->setDefinition($definition)
            ->setTeacher($teacher)
            ->setValue((string) $days);
        $this->persist($teacherValue);
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

    private function setNotifierSetting(EducationalCentre $centre, string $value): void
    {
        /** @var SettingDefinition $definition */
        $definition = $this->em->getRepository(SettingDefinition::class)
            ->findOneBy(['key' => 'notifications.report_notifier']);

        $centreValue = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue($value);
        $this->persist($centreValue);
    }
}
