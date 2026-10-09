<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\EmailDigestItem;
use App\Entity\EmailNotificationLog;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Entity\TeacherSettingValue;
use App\Service\EmailDigestSender;
use App\Service\IncidentEmailNotifier;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\RawMessage;

class EmailDigestTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private IncidentEmailNotifier $notifier;
    private EmailDigestSender $sender;
    private int $nextReportNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-10-05 05:00:00');

        /** @var IncidentEmailNotifier $notifier */
        $notifier       = self::getContainer()->get(IncidentEmailNotifier::class);
        $this->notifier = $notifier;
        /** @var EmailDigestSender $sender */
        $sender       = self::getContainer()->get(EmailDigestSender::class);
        $this->sender = $sender;

        $this->persist(
            (new SettingDefinition())->setKey('notifications.email_report_created')->setType(SettingType::Choice)->setDefaultValue('none')
                ->setGlobalScope(true)->setCentreScope(true)->setChoices('none,report_teacher,group_tutor,both'),
            (new SettingDefinition())->setKey('notifications.email_report_modified')->setType(SettingType::Choice)->setDefaultValue('none')
                ->setGlobalScope(true)->setCentreScope(true)->setChoices('none,report_teacher,group_tutor,both'),
            (new SettingDefinition())->setKey('notifications.email_log_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')
                ->setGlobalScope(true)->setCentreScope(true),
            (new SettingDefinition())->setKey('notifications.email_delivery')->setType(SettingType::Choice)->setDefaultValue('daily_digest')
                ->setGlobalScope(true)->setCentreScope(true)->setTeacherScope(true)->setChoices('immediate,daily_digest'),
            (new SettingDefinition())->setKey('notifications.email_digest_hour')->setType(SettingType::Integer)->setDefaultValue('7')
                ->setGlobalScope(true)->setCentreScope(true)->setTeacherScope(true)->setMinValue(0)->setMaxValue(23),
        );
    }

    public function testDigestRecipientGetsNoImmediateEmailsAndOneDigestWithEveryEvent(): void
    {
        [$report, $centre, , $creator, $actor] = $this->makeScenario('all');
        $this->setCentreValue('notifications.email_report_created', $centre, 'report_teacher');
        $this->setCentreValue('notifications.email_report_modified', $centre, 'report_teacher');

        // Sin tocar «Forma de recibir los avisos»: el valor por defecto es el resumen diario.
        $this->notifier->reportCreated($report, $actor);
        $this->notifier->reportModified($report, $actor);
        $this->notifier->reportModified($report, $actor);

        self::assertEmailCount(0);
        self::assertCount(3, $this->em->getRepository(EmailDigestItem::class)->findAll());

        self::assertSame(1, $this->sender->sendDue(ignoreHour: true));

        self::assertEmailCount(1);
        $message = $this->sentMessage(0);
        self::assertEmailAddressContains($message, 'To', 'creator@ejemplo.local');
        self::assertEmailSubjectContains($message, 'Resumen diario');
        self::assertEmailSubjectContains($message, '2'); // dos líneas distintas: registrado y modificado
        self::assertEmailHtmlBodyContains($message, 'registrado por');
        self::assertEmailHtmlBodyContains($message, '(2 veces)');

        // Ya enviado: una segunda pasada no vuelve a mandar nada y queda constancia en el registro.
        self::assertSame(0, $this->sender->sendDue(ignoreHour: true));
        self::assertEmailCount(1);
        $logs = $this->em->getRepository(EmailNotificationLog::class)->findBy(['eventKey' => 'daily_digest']);
        self::assertCount(1, $logs);
        self::assertTrue($logs[0]->isSuccess());
    }

    public function testTeacherLevelSettingOverridesTheCentreOne(): void
    {
        [$report, $centre, , $creator, $actor] = $this->makeScenario('override');
        $this->setCentreValue('notifications.email_report_created', $centre, 'report_teacher');
        $this->setCentreValue('notifications.email_delivery', $centre, 'daily_digest');
        $this->setTeacherValue('notifications.email_delivery', $creator, 'immediate');
        // (el centro lo fija a resumen, pero el docente elige recibirlo de forma inmediata)

        $this->notifier->reportCreated($report, $actor);

        self::assertEmailCount(1);
        self::assertSame([], $this->em->getRepository(EmailDigestItem::class)->findAll());
    }

    public function testImmediateRecipientsOnlyGetTheDailyRemindersInTheDigest(): void
    {
        [$report, , , $creator] = $this->makeScenario('reminders');
        $this->setTeacherValue('notifications.email_delivery', $creator, 'immediate');

        // Aunque reciba los avisos de forma inmediata, el de prescripción próxima se acumula y sale en el correo diario.
        $this->notifier->reportsNearingPrescription($creator, [['report' => $report, 'daysRemaining' => 2]]);

        self::assertEmailCount(0);
        self::assertSame(1, $this->sender->sendDue(ignoreHour: true));
        self::assertEmailCount(1);
        self::assertEmailHtmlBodyContains($this->sentMessage(0), 'prescribe en 2 días');
    }

    public function testDigestIsSentOnceThePersonalHourHasPassedAndOnlyWithLinesQueuedBeforeIt(): void
    {
        [$report, $centre, , $creator] = $this->makeScenario('hour');
        $this->setTeacherValue('notifications.email_digest_hour', $creator, '9');

        self::mockTime('2026-10-05 05:00:00');
        $this->notifier->reportsNearingPrescription($creator, [['report' => $report, 'daysRemaining' => 3]]);

        self::mockTime('2026-10-05 08:59:00');
        self::assertSame(0, $this->sender->sendDue(), 'Todavía no es la hora del resumen.');

        self::mockTime('2026-10-05 10:15:00'); // la ejecución llegó tarde: se envía igualmente, una sola vez
        $this->notifier->reportsNearingPrescription($creator, [['report' => $report, 'daysRemaining' => 1]]);
        self::assertSame(1, $this->sender->sendDue());
        self::assertEmailCount(1);
        self::assertEmailHtmlBodyContains($this->sentMessage(0), 'prescribe en 3 días');
        self::assertStringNotContainsString('prescribe mañana', (string) $this->sentMessage(0)->toString());

        // La línea registrada después de la hora espera al resumen del día siguiente.
        self::assertSame(0, $this->sender->sendDue());
        self::mockTime('2026-10-06 09:00:00');
        self::assertSame(1, $this->sender->sendDue());
        self::assertEmailCount(2);
    }

    public function testDigestIsKeptForRetryWhenTheTransportFails(): void
    {
        [$report, , , $creator] = $this->makeScenario('retry');
        $this->notifier->reportsNearingPrescription($creator, [['report' => $report, 'daysRemaining' => 2]]);

        // Sin correo del destinatario no se acumula nada (no hay a quién enviar).
        $withoutEmail = $this->makeTeacher('sin.correo', null);
        $this->persist($withoutEmail);
        $this->notifier->reportsNearingPrescription($withoutEmail, [['report' => $report, 'daysRemaining' => 2]]);

        self::assertCount(1, $this->em->getRepository(EmailDigestItem::class)->findAll());
    }

    public function testEveryDigestEventAndSectionHasATranslation(): void
    {
        /** @var \Symfony\Component\Translation\TranslatorBagInterface $translator */
        $translator = self::getContainer()->get('translator');
        $catalogue  = $translator->getCatalogue('es');

        $missing = [];
        foreach (\App\Service\EmailDigestSections::events() as $event) {
            if (!$catalogue->has('emails.digest.line.' . $event, 'emails')) {
                $missing[] = 'emails.digest.line.' . $event;
            }
        }
        foreach (\App\Service\EmailDigestSections::ORDER as $section) {
            if (!$catalogue->has('emails.digest.section.' . $section, 'emails')) {
                $missing[] = 'emails.digest.section.' . $section;
            }
        }

        self::assertSame([], $missing);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: IncidentReport, 1: EducationalCentre, 2: Group, 3: Teacher, 4: Teacher}
     */
    private function makeScenario(string $suffix): array
    {
        $centre  = (new EducationalCentre())->setCode('44000' . substr(md5($suffix), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('nie-' . $suffix . uniqid('', false));
        $creator = $this->makeTeacher('creator.' . $suffix . uniqid('', false), 'creator@ejemplo.local');
        $actor   = $this->makeTeacher('actor.' . $suffix . uniqid('', false), 'actor@ejemplo.local');
        $this->persist($centre, $year, $course, $group, $student, $creator, $actor);

        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable('2026-10-01'))
            ->setDescription('Incidente de prueba')
            ->setExpelledFromClass(false);
        $this->persist($report);

        return [$report, $centre, $group, $creator, $actor];
    }

    private function makeTeacher(string $username, ?string $email): Teacher
    {
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
        if ($email !== null) {
            $teacher->setEmail($email);
        }

        return $teacher;
    }

    private function definition(string $key): SettingDefinition
    {
        $definition = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => $key]);
        self::assertNotNull($definition);

        return $definition;
    }

    private function setCentreValue(string $key, EducationalCentre $centre, string $value): void
    {
        $this->persist((new CentreSettingValue())->setDefinition($this->definition($key))->setCentre($centre)->setValue($value));
    }

    private function setTeacherValue(string $key, Teacher $teacher, string $value): void
    {
        $this->persist((new TeacherSettingValue())->setDefinition($this->definition($key))->setTeacher($teacher)->setValue($value));
    }

    /** getMailerMessage() devuelve también los eventos «en cola»: nos quedamos con los correos realmente enviados. */
    private function sentMessage(int $index): RawMessage
    {
        $sent = array_values(array_filter(self::getMailerEvents(), static fn ($event) => !$event->isQueued()));
        $message = $sent[$index]->getMessage();
        self::assertInstanceOf(RawMessage::class, $message);

        return $message;
    }
}
