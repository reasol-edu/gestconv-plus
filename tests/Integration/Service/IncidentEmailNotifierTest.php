<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\EmailNotificationLog;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\Sanction;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\CommunicationRepository;
use App\Repository\IncidentReportObservationRepository;
use App\Repository\SanctionObservationRepository;
use App\Service\AppSettingsInterface;
use App\Service\IncidentEmailNotifier;
use App\Service\PdfHeaderBuilder;
use App\Service\PdfRenderer;
use App\Tests\Integration\RepositoryTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    // ── report_auto_prescribed ───────────────────────────────────────────────

    public function testReportAutoPrescribedReusesReportPrescribedSetting(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('auto_prescribed.setting');
        $this->setSetting('notifications.email_report_prescribed', $centre, 'report_teacher');

        $this->notifier->reportAutoPrescribed($report);

        self::assertEmailCount(1);
        self::assertEmailAddressContains($this->sentMessage(0), 'To', 'creator@ejemplo.local');
    }

    public function testReportAutoPrescribedSendsToNobodyWhenSettingIsNone(): void
    {
        [$report, $centre] = $this->makeScenario('auto_prescribed.none');
        $this->setSetting('notifications.email_report_prescribed', $centre, 'none');

        $this->notifier->reportAutoPrescribed($report);

        self::assertEmailCount(0);
    }

    public function testReportAutoPrescribedSubjectHasNoActor(): void
    {
        [$report, $centre] = $this->makeScenario('auto_prescribed.subject');
        $this->setSetting('notifications.email_report_prescribed', $centre, 'report_teacher');

        $this->notifier->reportAutoPrescribed($report);

        self::assertEmailCount(1);
        self::assertEmailSubjectContains($this->sentMessage(0), 'Ana García');
        self::assertEmailHtmlBodyContains($this->sentMessage(0), (string) $report->getNumber());
    }

    // ── report_prescription_warning ───────────────────────────────────────────

    public function testReportsNearingPrescriptionSendsOneEmailWithAllItems(): void
    {
        [$report1, , , $creator] = $this->makeScenario('warning.multi.1');
        [$report2] = $this->makeScenario('warning.multi.2');

        $this->notifier->reportsNearingPrescription($creator, [
            ['report' => $report1, 'daysRemaining' => 2],
            ['report' => $report2, 'daysRemaining' => 0],
        ]);

        self::assertEmailCount(1);
        self::assertEmailAddressContains($this->sentMessage(0), 'To', 'creator@ejemplo.local');
        self::assertEmailSubjectContains($this->sentMessage(0), '2');
        self::assertEmailHtmlBodyContains($this->sentMessage(0), (string) $report1->getNumber());
        self::assertEmailHtmlBodyContains($this->sentMessage(0), (string) $report2->getNumber());
    }

    public function testReportsNearingPrescriptionSendsNothingWhenItemsIsEmpty(): void
    {
        [, , , $creator] = $this->makeScenario('warning.empty');

        $this->notifier->reportsNearingPrescription($creator, []);

        self::assertEmailCount(0);
    }

    public function testReportsNearingPrescriptionSkipsRecipientWithoutEmail(): void
    {
        [$report] = $this->makeScenario('warning.noemail');
        $teacherWithoutEmail = $this->makeTeacher('teacher.warning.noemail' . uniqid('', false));
        $this->persist($teacherWithoutEmail);

        $this->notifier->reportsNearingPrescription($teacherWithoutEmail, [
            ['report' => $report, 'daysRemaining' => 1],
        ]);

        self::assertEmailCount(0);
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

    // ── adjuntar PDF (parte/sanción) ─────────────────────────────────────────

    public function testReportAttachPdfSettingAttachesReportPdf(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('attach.report');
        $this->setSetting('notifications.email_report_created', $centre, 'report_teacher');
        $this->setBooleanSetting('notifications.email_report_attach_pdf', $centre, true);

        $this->notifier->reportCreated($report, $creator);

        self::assertEmailCount(1);
        self::assertEmailAttachmentCount($this->sentMessage(0), 1);
    }

    public function testReportAttachPdfSettingDisabledSendsNoAttachment(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('attach.report.off');
        $this->setSetting('notifications.email_report_created', $centre, 'report_teacher');
        $this->setBooleanSetting('notifications.email_report_attach_pdf', $centre, false);

        $this->notifier->reportCreated($report, $creator);

        self::assertEmailCount(1);
        self::assertEmailAttachmentCount($this->sentMessage(0), 0);
    }

    public function testSanctionAttachPdfSettingAttachesSanctionPdf(): void
    {
        [$report, $centre, $group, $creator] = $this->makeScenario('attach.sanction');

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
        $sanction->getReports()->add($report);
        $this->flush();

        $this->setSetting('notifications.email_sanction_notified', $centre, 'report_teacher');
        $this->setBooleanSetting('notifications.email_sanction_attach_pdf', $centre, true);

        $this->notifier->sanctionNotified($sanction, $creator);

        self::assertEmailCount(1);
        self::assertEmailAttachmentCount($this->sentMessage(0), 1);
    }

    public function testReportAttachPdfSettingDoesNotAffectNotificationLog(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('attach.log');
        $this->setSetting('notifications.email_report_created', $centre, 'report_teacher');
        $this->setBooleanSetting('notifications.email_report_attach_pdf', $centre, true);
        $this->setBooleanSetting('notifications.email_log_enabled', $centre, true);

        $this->notifier->reportCreated($report, $creator);

        $logs = $this->findLogs($centre);
        self::assertCount(1, $logs);
        self::assertSame('report_created', $logs[0]->getEventKey());
    }

    // ── registro de avisos (email_notification_log) ─────────────────────────

    public function testLogIsNotPersistedWhenSettingIsAbsent(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('log.absent');
        $this->setSetting('notifications.email_report_created', $centre, 'report_teacher');

        $this->notifier->reportCreated($report, $creator);

        self::assertEmailCount(1);
        self::assertCount(0, $this->findLogs($centre));
    }

    public function testLogIsNotPersistedWhenSettingIsDisabled(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('log.disabled');
        $this->setSetting('notifications.email_report_created', $centre, 'report_teacher');
        $this->setBooleanSetting('notifications.email_log_enabled', $centre, false);

        $this->notifier->reportCreated($report, $creator);

        self::assertEmailCount(1);
        self::assertCount(0, $this->findLogs($centre));
    }

    public function testSuccessfulSendIsLoggedWhenSettingIsEnabled(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('log.enabled');
        $this->setSetting('notifications.email_report_created', $centre, 'report_teacher');
        $this->setBooleanSetting('notifications.email_log_enabled', $centre, true);

        $this->notifier->reportCreated($report, $creator);

        $logs = $this->findLogs($centre);
        self::assertCount(1, $logs);
        self::assertSame('report_created', $logs[0]->getEventKey());
        self::assertTrue($logs[0]->isSuccess());
        self::assertNull($logs[0]->getErrorMessage());
        self::assertSame('creator@ejemplo.local', $logs[0]->getRecipient()?->getEmail());
    }

    public function testFailedSendIsLoggedWithErrorMessage(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('log.failed');
        $this->setSetting('notifications.email_report_created', $centre, 'report_teacher');
        $this->setBooleanSetting('notifications.email_log_enabled', $centre, true);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new TransportException('SMTP caído'));

        $this->makeNotifierWithMailer($mailer)->reportCreated($report, $creator);

        $logs = $this->findLogs($centre);
        self::assertCount(1, $logs);
        self::assertFalse($logs[0]->isSuccess());
        self::assertSame('SMTP caído', $logs[0]->getErrorMessage());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: IncidentReport, 1: EducationalCentre, 2: Group, 3: Teacher}
     */
    private function makeScenario(string $suffix = ''): array
    {
        $centre    = (new EducationalCentre())->setCode('41000' . substr(md5($suffix), 0, 3))->setName('IES')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . $suffix . uniqid('', false));
        $creator   = $this->makeTeacher('creator.' . $suffix . uniqid('', false), 'creator@ejemplo.local');

        $this->persist($centre, $year, $course, $group, $student, $creator);

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

    private function setBooleanSetting(string $key, EducationalCentre $centre, bool $value): void
    {
        $definition = (new SettingDefinition())
            ->setKey($key)
            ->setType(SettingType::Boolean)
            ->setDefaultValue('true')
            ->setGlobalScope(true)
            ->setCentreScope(true);
        $this->persist($definition);

        $centreValue = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue($value ? 'true' : 'false');
        $this->persist($centreValue);
    }

    /** @return list<EmailNotificationLog> */
    private function findLogs(EducationalCentre $centre): array
    {
        return $this->em->getRepository(EmailNotificationLog::class)->findBy(['educationalCentre' => $centre]);
    }

    /**
     * Construye una nueva instancia del notificador con un MailerInterface propio (en vez
     * del reutilizado en self::$notifier), para poder simular fallos de envío sin afectar
     * al resto de tests: el resto de dependencias son las reales del contenedor de test.
     */
    private function makeNotifierWithMailer(MailerInterface $mailer): IncidentEmailNotifier
    {
        return new IncidentEmailNotifier(
            $mailer,
            self::getContainer()->get(AppSettingsInterface::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(TranslatorInterface::class),
            self::getContainer()->get(LoggerInterface::class),
            $this->em,
            self::getContainer()->get(PdfRenderer::class),
            self::getContainer()->get(PdfHeaderBuilder::class),
            self::getContainer()->get(IncidentReportObservationRepository::class),
            self::getContainer()->get(SanctionObservationRepository::class),
            self::getContainer()->get(CommunicationRepository::class),
            'no-responder@ejemplo.local',
            'GestConv+',
        );
    }
}
