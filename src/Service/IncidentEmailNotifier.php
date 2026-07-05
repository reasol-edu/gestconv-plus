<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\EmailNotificationLog;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class IncidentEmailNotifier
{
    /** @var array<string, string> */
    private const REPORT_EVENT_SETTINGS = [
        'created'    => 'notifications.email_report_created',
        'notified'   => 'notifications.email_report_notified',
        'modified'   => 'notifications.email_report_modified',
        'deleted'    => 'notifications.email_report_deleted',
        'prescribed' => 'notifications.email_report_prescribed',
        'sanctioned' => 'notifications.email_report_sanctioned',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AppSettingsInterface $settings,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $fromAddress,
        #[Autowire('%app.name%')]
        private readonly string $appName,
    ) {}

    public function reportCreated(IncidentReport $report, Teacher $actor): void
    {
        $this->notifyReportEvent($report, 'created', $actor);
    }

    public function reportNotified(IncidentReport $report, Teacher $actor): void
    {
        $this->notifyReportEvent($report, 'notified', $actor);
        $this->notifySanctionableCommittee($report);
    }

    public function reportModified(IncidentReport $report, Teacher $actor): void
    {
        $this->notifyReportEvent($report, 'modified', $actor);
    }

    public function reportDeleted(IncidentReport $report, Teacher $actor): void
    {
        $this->notifyReportEvent($report, 'deleted', $actor, withLink: false);
    }

    public function reportPrescribed(IncidentReport $report, Teacher $actor): void
    {
        $this->notifyReportEvent($report, 'prescribed', $actor);
    }

    /** Like {@see reportPrescribed()} but for automatic (cron-triggered) prescriptions, with no human actor. */
    public function reportAutoPrescribed(IncidentReport $report): void
    {
        $centre = $this->centreForGroup($report->getGroup());
        $choice = $this->choiceFor(self::REPORT_EVENT_SETTINGS['prescribed'], $centre);
        if ($choice === 'none') {
            return;
        }

        $recipients = $this->recipientsFor($choice, [$report->getRegisteredBy()], $report->getGroup()->getTutors());
        if ($recipients === []) {
            return;
        }

        $url = $this->urlGenerator->generate('app_incidents_show', ['id' => $report->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL);

        $params = [
            '%number%'  => $report->getNumber(),
            '%student%' => $this->fullName($report->getStudent()),
            '%group%'   => $report->getGroup()->getName(),
        ];

        foreach ($recipients as $teacher) {
            $this->dispatch($centre, $teacher, 'report_auto_prescribed', $params, 'email/incident_report_notice.html.twig', [
                'report'    => $report,
                'reportUrl' => $url,
            ]);
        }
    }

    /**
     * Sends a single daily digest email to a teacher listing the reports nearing automatic
     * prescription. Eligibility and recipients are already resolved by the caller
     * ({@see \App\MessageHandler\WarnUpcomingReportPrescriptionsHandler}); this method only
     * formats and sends, unlike the per-event methods above which resolve their own choice setting.
     *
     * @param list<array{report: IncidentReport, daysRemaining: int}> $items
     */
    public function reportsNearingPrescription(Teacher $teacher, array $items): void
    {
        if ($items === []) {
            return;
        }

        $centre = $this->centreForGroup($items[0]['report']->getGroup());

        $rows = array_map(
            fn (array $item): array => [
                'report'        => $item['report'],
                'daysRemaining' => $item['daysRemaining'],
                'url'           => $this->urlGenerator->generate(
                    'app_incidents_show',
                    ['id' => $item['report']->getId()->toRfc4122()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
            $items,
        );

        $this->dispatch(
            $centre,
            $teacher,
            'report_prescription_warning',
            ['%count%' => count($items)],
            'email/report_prescription_warning.html.twig',
            ['rows' => $rows],
        );
    }

    public function reportSanctioned(IncidentReport $report, Teacher $actor): void
    {
        $this->notifyReportEvent($report, 'sanctioned', $actor);
    }

    public function sanctionNotified(Sanction $sanction, Teacher $actor): void
    {
        $centre = $this->centreForGroup($sanction->getGroup());
        $choice = $this->choiceFor('notifications.email_sanction_notified', $centre);
        if ($choice === 'none') {
            return;
        }

        /** @var array<string, Teacher> $reportTeachers */
        $reportTeachers = [];
        foreach ($sanction->getReports() as $report) {
            $teacher                                            = $report->getRegisteredBy();
            $reportTeachers[$teacher->getId()->toRfc4122()] = $teacher;
        }

        $recipients = $this->recipientsFor($choice, $reportTeachers, $sanction->getGroup()->getTutors());
        if ($recipients === []) {
            return;
        }

        $url = $this->urlGenerator->generate(
            'app_sanctions_show',
            ['id' => $sanction->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $params = [
            '%actor%'   => $this->fullName($actor),
            '%student%' => $this->fullName($sanction->getStudent()),
            '%group%'   => $sanction->getGroup()->getName(),
        ];

        foreach ($recipients as $teacher) {
            $this->dispatch($centre, $teacher, 'sanction_notified', $params, 'email/sanction_notice.html.twig', [
                'sanction'    => $sanction,
                'sanctionUrl' => $url,
            ]);
        }
    }

    private function notifyReportEvent(IncidentReport $report, string $event, Teacher $actor, bool $withLink = true): void
    {
        $centre = $this->centreForGroup($report->getGroup());
        $choice = $this->choiceFor(self::REPORT_EVENT_SETTINGS[$event], $centre);
        if ($choice === 'none') {
            return;
        }

        $recipients = $this->recipientsFor($choice, [$report->getRegisteredBy()], $report->getGroup()->getTutors());
        if ($recipients === []) {
            return;
        }

        $url = $withLink
            ? $this->urlGenerator->generate('app_incidents_show', ['id' => $report->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL)
            : null;

        $params = [
            '%actor%'   => $this->fullName($actor),
            '%number%'  => $report->getNumber(),
            '%student%' => $this->fullName($report->getStudent()),
            '%group%'   => $report->getGroup()->getName(),
        ];

        foreach ($recipients as $teacher) {
            $this->dispatch($centre, $teacher, "report_$event", $params, 'email/incident_report_notice.html.twig', [
                'report'    => $report,
                'reportUrl' => $url,
            ]);
        }
    }

    private function notifySanctionableCommittee(IncidentReport $report): void
    {
        if ($report->isPrescribed() || $report->getSanction() !== null) {
            return;
        }

        $centre = $this->centreForGroup($report->getGroup());
        $choice = $this->choiceFor('notifications.email_report_sanctionable_committee', $centre);
        if ($choice !== 'committee') {
            return;
        }

        $url = $this->urlGenerator->generate('app_incidents_show', ['id' => $report->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL);

        $params = [
            '%number%'  => $report->getNumber(),
            '%student%' => $this->fullName($report->getStudent()),
            '%group%'   => $report->getGroup()->getName(),
        ];

        foreach ($centre->getCommitteeMembers() as $member) {
            $this->dispatch($centre, $member, 'report_sanctionable_committee', $params, 'email/incident_report_notice.html.twig', [
                'report'    => $report,
                'reportUrl' => $url,
            ]);
        }
    }

    /**
     * @param iterable<Teacher> $reportTeachers
     * @param iterable<Teacher> $tutors
     * @return list<Teacher>
     */
    private function recipientsFor(string $choice, iterable $reportTeachers, iterable $tutors): array
    {
        /** @var array<string, Teacher> $recipients */
        $recipients = [];

        if ($choice === 'report_teacher' || $choice === 'both') {
            foreach ($reportTeachers as $teacher) {
                $recipients[$teacher->getId()->toRfc4122()] = $teacher;
            }
        }

        if ($choice === 'group_tutor' || $choice === 'both') {
            foreach ($tutors as $teacher) {
                $recipients[$teacher->getId()->toRfc4122()] = $teacher;
            }
        }

        return array_values($recipients);
    }

    /**
     * @param array<string, string|int> $params
     * @param array<string, mixed> $context
     */
    private function dispatch(EducationalCentre $centre, Teacher $teacher, string $eventKey, array $params, string $template, array $context): void
    {
        $email = $teacher->getEmail();
        if ($email === null) {
            return;
        }

        $this->send($centre, $teacher, $eventKey, (new TemplatedEmail())
            ->to(new Address($email, $this->fullName($teacher)))
            ->subject($this->translator->trans("emails.$eventKey.subject", $params, 'emails'))
            ->htmlTemplate($template)
            ->context($context + [
                'teacher'     => $teacher,
                'params'      => $params,
                'transPrefix' => "emails.$eventKey",
            ]));
    }

    private function send(EducationalCentre $centre, Teacher $recipient, string $eventKey, TemplatedEmail $email): void
    {
        $email->from(new Address($this->fromAddress, $this->appName));

        $success      = true;
        $errorMessage = null;

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $success      = false;
            $errorMessage = $e->getMessage();
            $this->logger->error('No se pudo enviar el email "{subject}": {error}', [
                'subject' => $email->getSubject(),
                'error'   => $e->getMessage(),
            ]);
        }

        $this->logNotification($centre, $recipient, $eventKey, (string) $email->getSubject(), $success, $errorMessage);
    }

    private function logNotification(
        EducationalCentre $centre,
        Teacher $recipient,
        string $eventKey,
        string $subject,
        bool $success,
        ?string $errorMessage,
    ): void {
        if (!$this->settings->getForCentre('notifications.email_log_enabled', $centre)) {
            return;
        }

        $this->em->persist(new EmailNotificationLog(
            $centre,
            $recipient,
            $this->fullName($recipient),
            $eventKey,
            $subject,
            $success,
            $errorMessage,
            new \DateTimeImmutable(),
        ));
        $this->em->flush();
    }

    private function choiceFor(string $key, EducationalCentre $centre): string
    {
        $value = $this->settings->getForCentre($key, $centre);

        return is_string($value) ? $value : 'none';
    }

    private function centreForGroup(Group $group): EducationalCentre
    {
        return $group->getProgrammeYear()->getProgramme()->getAcademicYear()->getEducationalCentre();
    }

    private function fullName(Teacher|Student $person): string
    {
        return $person->getName()->getFirstName() . ' ' . $person->getName()->getLastName();
    }
}
