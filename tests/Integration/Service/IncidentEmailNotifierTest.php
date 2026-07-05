<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Sanction;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Service\IncidentEmailNotifier;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Mime\RawMessage;

class IncidentEmailNotifierTest extends RepositoryTestCase
{
    private IncidentEmailNotifier $notifier;
    private int $nextReportNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var IncidentEmailNotifier $notifier */
        $notifier       = self::getContainer()->get(IncidentEmailNotifier::class);
        $this->notifier = $notifier;
    }

    // ── report_created ───────────────────────────────────────────────────────

    public function testReportCreatedSendsToTeacherAndTutorWhenSettingIsBoth(): void
    {
        [$report, $centre, $group, $creator] = $this->makeScenario('created.both');
        $tutor = $this->makeTeacher('tutor.created.both', 'tutor@ejemplo.local');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        $this->setSetting('notifications.email_report_created', $centre, 'both');

        $this->notifier->reportCreated($report, $creator);

        self::assertEmailCount(2);
        self::assertEmailAddressContains($this->sentMessage(0), 'To', 'creator@ejemplo.local');
        self::assertEmailAddressContains($this->sentMessage(1), 'To', 'tutor@ejemplo.local');
    }

    public function testReportCreatedSendsToNobodyWhenSettingIsNone(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('created.none');
        $this->setSetting('notifications.email_report_created', $centre, 'none');

        $this->notifier->reportCreated($report, $creator);

        self::assertEmailCount(0);
    }

    public function testReportCreatedSendsOnlyToReportTeacher(): void
    {
        [$report, $centre, $group, $creator] = $this->makeScenario('created.rt');
        $tutor = $this->makeTeacher('tutor.created.rt', 'tutor.rt@ejemplo.local');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $this->flush();

        $this->setSetting('notifications.email_report_created', $centre, 'report_teacher');

        $this->notifier->reportCreated($report, $creator);

        self::assertEmailCount(1);
        self::assertEmailAddressContains($this->sentMessage(0), 'To', 'creator@ejemplo.local');
    }

    public function testReportCreatedDeduplicatesWhenTeacherIsAlsoTutor(): void
    {
        [$report, $centre, $group, $creator] = $this->makeScenario('created.dedup');
        $group->addTutor($creator);
        $this->flush();

        $this->setSetting('notifications.email_report_created', $centre, 'both');

        $this->notifier->reportCreated($report, $creator);

        self::assertEmailCount(1);
    }

    public function testReportCreatedSkipsRecipientWithoutEmail(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('created.noemail');
        $creator->setEmail(null);
        $this->flush();

        $this->setSetting('notifications.email_report_created', $centre, 'report_teacher');

        $this->notifier->reportCreated($report, $creator);

        self::assertEmailCount(0);
    }

    public function testReportCreatedSubjectAndBodyMentionStudent(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('created.subject');
        $this->setSetting('notifications.email_report_created', $centre, 'report_teacher');

        $this->notifier->reportCreated($report, $creator);

        self::assertEmailCount(1);
        self::assertEmailSubjectContains($this->sentMessage(0), 'Ana García');
        self::assertEmailHtmlBodyContains($this->sentMessage(0), (string) $report->getNumber());
    }

    // ── report_notified + comisión de convivencia ───────────────────────────

    public function testReportNotifiedAlsoNotifiesCommitteeWhenEligible(): void
    {
        [$report, $centre] = $this->makeScenario('notified.committee');
        $committee = $this->makeTeacher('committee.notified', 'committee@ejemplo.local');
        $this->persist($committee);
        $centre->addCommitteeMember($committee);
        $this->flush();

        $this->setSetting('notifications.email_report_notified', $centre, 'none');
        $this->setSetting('notifications.email_report_sanctionable_committee', $centre, 'committee');

        $this->notifier->reportNotified($report, $report->getRegisteredBy());

        self::assertEmailCount(1);
        self::assertEmailAddressContains($this->sentMessage(0), 'To', 'committee@ejemplo.local');
    }

    public function testReportNotifiedDoesNotNotifyCommitteeWhenAlreadyPrescribed(): void
    {
        [$report, $centre] = $this->makeScenario('notified.prescribed');
        $committee = $this->makeTeacher('committee.prescribed', 'committee2@ejemplo.local');
        $this->persist($committee);
        $centre->addCommitteeMember($committee);
        $report->setPrescribedAt(new \DateTimeImmutable());
        $this->flush();

        $this->setSetting('notifications.email_report_notified', $centre, 'none');
        $this->setSetting('notifications.email_report_sanctionable_committee', $centre, 'committee');

        $this->notifier->reportNotified($report, $report->getRegisteredBy());

        self::assertEmailCount(0);
    }

    public function testReportNotifiedDoesNotNotifyCommitteeWhenAlreadySanctioned(): void
    {
        [$report, $centre, $group, $creator] = $this->makeScenario('notified.sanctioned');
        $committee = $this->makeTeacher('committee.sanctioned', 'committee3@ejemplo.local');
        $this->persist($committee);
        $centre->addCommitteeMember($committee);

        $sanction = (new Sanction())
            ->setAcademicYear($report->getAcademicYear())
            ->setStudent($report->getStudent())
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setDetails('Detalle')
            ->setNoMeasureApplied(true)
            ->setNoMeasureReason('Sin medida');
        $this->persist($sanction);
        $report->setSanction($sanction);
        $this->flush();

        $this->setSetting('notifications.email_report_notified', $centre, 'none');
        $this->setSetting('notifications.email_report_sanctionable_committee', $centre, 'committee');

        $this->notifier->reportNotified($report, $creator);

        self::assertEmailCount(0);
    }

    public function testReportNotifiedCommitteeSettingNoneSendsNothing(): void
    {
        [$report, $centre] = $this->makeScenario('notified.committee.none');
        $committee = $this->makeTeacher('committee.none', 'committee4@ejemplo.local');
        $this->persist($committee);
        $centre->addCommitteeMember($committee);
        $this->flush();

        $this->setSetting('notifications.email_report_notified', $centre, 'none');
        $this->setSetting('notifications.email_report_sanctionable_committee', $centre, 'none');

        $this->notifier->reportNotified($report, $report->getRegisteredBy());

        self::assertEmailCount(0);
    }

    // ── report_deleted (sin enlace) ──────────────────────────────────────────

    public function testReportDeletedEmailHasNoLink(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('deleted');
        $this->setSetting('notifications.email_report_deleted', $centre, 'report_teacher');

        $this->notifier->reportDeleted($report, $creator);

        self::assertEmailCount(1);
        self::assertEmailHtmlBodyNotContains($this->sentMessage(0), 'href="http');
    }

    // ── report_sanctioned ─────────────────────────────────────────────────────

    public function testReportSanctionedUsesItsOwnSetting(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('sanctioned');
        $this->setSetting('notifications.email_report_sanctioned', $centre, 'report_teacher');

        $this->notifier->reportSanctioned($report, $creator);

        self::assertEmailCount(1);
    }

    // ── sanction_notified ─────────────────────────────────────────────────────

    public function testSanctionNotifiedNotifiesEachReportTeacherAndGroupTutor(): void
    {
        [$report1, $centre, $group, $creator1] = $this->makeScenario('sanction.notified.1');
        $creator2 = $this->makeTeacher('creator.sanction.notified.2', 'creator2@ejemplo.local');
        $this->persist($creator2);

        $tutor = $this->makeTeacher('tutor.sanction.notified', 'tutor.sn@ejemplo.local');
        $this->persist($tutor);
        $group->addTutor($tutor);

        $student2 = (new Student(new PersonName('Beatriz', 'Ruiz')))->setStudentId('nie-' . uniqid('', false));
        $this->persist($student2);

        $report2 = (new IncidentReport())
            ->setAcademicYear($report1->getAcademicYear())
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($student2)
            ->setGroup($group)
            ->setRegisteredBy($creator2)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('Segundo parte')
            ->setExpelledFromClass(false);
        $this->persist($report2);

        $sanction = (new Sanction())
            ->setAcademicYear($report1->getAcademicYear())
            ->setStudent($report1->getStudent())
            ->setGroup($group)
            ->setRegisteredBy($creator1)
            ->setDetails('Detalle')
            ->setNoMeasureApplied(true)
            ->setNoMeasureReason('Sin medida');
        $this->persist($sanction);
        $report1->setSanction($sanction);
        $report2->setSanction($sanction);
        // IncidentReport es el lado propietario de la relación; como $sanction
        // nunca se vuelve a cargar desde la BD, hay que sincronizar a mano el
        // lado inverso para que getReports() los refleje en memoria.
        $sanction->getReports()->add($report1);
        $sanction->getReports()->add($report2);
        $this->flush();

        $this->setSetting('notifications.email_sanction_notified', $centre, 'both');

        $this->notifier->sanctionNotified($sanction, $creator1);

        // creator1, creator2 y tutor: 3 destinatarios únicos
        self::assertEmailCount(3);
    }

    public function testSanctionNotifiedNoneSettingSendsNothing(): void
    {
        [$report, $centre, $group, $creator] = $this->makeScenario('sanction.notified.none');

        $sanction = (new Sanction())
            ->setAcademicYear($report->getAcademicYear())
            ->setStudent($report->getStudent())
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setDetails('Detalle')
            ->setNoMeasureApplied(true)
            ->setNoMeasureReason('Sin medida');
        $this->persist($sanction);
        $report->setSanction($sanction);
        $this->flush();

        $this->setSetting('notifications.email_sanction_notified', $centre, 'none');

        $this->notifier->sanctionNotified($sanction, $creator);

        self::assertEmailCount(0);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: IncidentReport, 1: EducationalCentre, 2: Group, 3: Teacher}
     */
    private function makeScenario(string $suffix = ''): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix), 0, 3))->setName('IES')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA' . $suffix)->setProgrammeYear($level);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . $suffix . uniqid('', false));
        $creator   = $this->makeTeacher('creator.' . $suffix . uniqid('', false), 'creator@ejemplo.local');

        $this->persist($centre, $year, $programme, $level, $group, $student, $creator);

        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('Incidente de prueba')
            ->setExpelledFromClass(false);

        $this->persist($report);

        return [$report, $centre, $group, $creator];
    }

    private function makeTeacher(string $username, ?string $email = null): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))
            ->setUsername($username)
            ->setEmail($email);
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

    private function setSetting(string $key, EducationalCentre $centre, string $value): void
    {
        $definition = (new SettingDefinition())
            ->setKey($key)
            ->setType(SettingType::Choice)
            ->setDefaultValue('none')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setChoices('none,report_teacher,group_tutor,both,committee');
        $this->persist($definition);

        $centreValue = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue($value);
        $this->persist($centreValue);
    }
}
