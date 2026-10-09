<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\EmailDigestItem;
use App\Entity\EmailNotificationLog;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\CommunicationRepository;
use App\Repository\IncidentReportObservationRepository;
use App\Repository\SanctionObservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
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
        private readonly PdfRenderer $pdfRenderer,
        private readonly PdfHeaderBuilder $pdfHeaderBuilder,
        private readonly IncidentReportObservationRepository $observations,
        private readonly SanctionObservationRepository $sanctionObservations,
        private readonly CommunicationRepository $communications,
        private readonly ClockInterface $clock,
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

        $attachment = $this->reportPdfAttachment($report, $centre);

        foreach ($recipients as $teacher) {
            $this->dispatch($centre, $teacher, 'report_auto_prescribed', $params, 'email/incident_report_notice.html.twig', [
                'report'    => $report,
                'reportUrl' => $url,
            ], $attachment);
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

        foreach ($items as $item) {
            $report = $item['report'];
            $this->queueDigestItem($centre, $teacher, 'report_prescription_warning', [
                '%number%'  => $report->getNumber(),
                '%student%' => $this->fullName($report->getStudent()),
                '%group%'   => $report->getGroup()->getName(),
                '%count%'   => max(0, $item['daysRemaining']),
            ], $this->urlGenerator->generate('app_incidents_show', ['id' => $report->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL));
        }
    }

    /**
     * Tells the sanction's registrant and/or the group's tutors (per "notifications.email_sanction_prescribed")
     * that an un-notified sanction has prescribed automatically. There is no human actor: the daily
     * cron job triggers it ({@see \App\MessageHandler\AutoPrescribeSanctionsHandler}).
     */
    public function sanctionAutoPrescribed(Sanction $sanction): void
    {
        $this->notifySanctionPrescribed($sanction, 'sanction_auto_prescribed', null);
    }

    /** Like {@see sanctionAutoPrescribed()} but when an administrator marks the sanction as prescribed by hand. */
    public function sanctionPrescribed(Sanction $sanction, Teacher $actor): void
    {
        $this->notifySanctionPrescribed($sanction, 'sanction_prescribed', $actor);
    }

    private function notifySanctionPrescribed(Sanction $sanction, string $eventKey, ?Teacher $actor): void
    {
        $centre = $this->centreForGroup($sanction->getGroup());
        $choice = $this->choiceFor('notifications.email_sanction_prescribed', $centre);
        if ($choice === 'none') {
            return;
        }

        $recipients = $this->recipientsFor($choice, [$sanction->getRegisteredBy()], $sanction->getGroup()->getTutors());
        if ($recipients === []) {
            return;
        }

        $url = $this->urlGenerator->generate(
            'app_sanctions_show',
            ['id' => $sanction->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $params = [
            '%student%' => $this->fullName($sanction->getStudent()),
            '%group%'   => $sanction->getGroup()->getName(),
        ];
        if ($actor !== null) {
            $params['%actor%'] = $this->fullName($actor);
        }

        foreach ($recipients as $teacher) {
            $this->dispatch($centre, $teacher, $eventKey, $params, 'email/sanction_notice.html.twig', [
                'sanction'    => $sanction,
                'sanctionUrl' => $url,
            ]);
        }
    }

    /**
     * Daily digest for a teacher listing the sanctions nearing automatic prescription. Eligibility and
     * recipients are resolved by {@see \App\MessageHandler\WarnUpcomingSanctionPrescriptionsHandler}.
     *
     * @param list<array{sanction: Sanction, daysRemaining: int}> $items
     */
    public function sanctionsNearingPrescription(Teacher $teacher, array $items): void
    {
        if ($items === []) {
            return;
        }

        $centre = $this->centreForGroup($items[0]['sanction']->getGroup());

        foreach ($items as $item) {
            $sanction = $item['sanction'];
            $this->queueDigestItem($centre, $teacher, 'sanction_prescription_warning', [
                '%student%' => $this->fullName($sanction->getStudent()),
                '%group%'   => $sanction->getGroup()->getName(),
                '%count%'   => max(0, $item['daysRemaining']),
            ], $this->urlGenerator->generate('app_sanctions_show', ['id' => $sanction->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL));
        }
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

        $attachment = $this->sanctionPdfAttachment($sanction, $centre);

        foreach ($recipients as $teacher) {
            $this->dispatch($centre, $teacher, 'sanction_notified', $params, 'email/sanction_notice.html.twig', [
                'sanction'    => $sanction,
                'sanctionUrl' => $url,
            ], $attachment);
        }
    }

    /**
     * Sends one immediate email per teacher when new sanction tasks are assigned to them,
     * called from {@see \App\Service\SanctionTaskGenerator::generateFor()} right after
     * persisting a batch of new tasks. A teacher with several subjects in the group gets a
     * single email listing all of their pending subjects. Respects
     * notifications.email_sanction_task_assigned.
     *
     * @param list<SanctionTask> $tasks
     */
    public function sanctionTasksAssigned(Sanction $sanction, array $tasks): void
    {
        if ($tasks === []) {
            return;
        }

        $centre = $this->centreForGroup($sanction->getGroup());
        if ($this->settings->getForCentre('notifications.email_sanction_task_assigned', $centre) !== true) {
            return;
        }

        /** @var array<string, Teacher> $teachersById */
        $teachersById = [];
        /** @var array<string, list<SanctionTask>> $tasksByTeacher */
        $tasksByTeacher = [];
        foreach ($tasks as $task) {
            $teacher                     = $task->getGroupTeacher()->getTeacher();
            $teacherId                   = $teacher->getId()->toRfc4122();
            $teachersById[$teacherId]    = $teacher;
            $tasksByTeacher[$teacherId][] = $task;
        }

        $params = [
            '%student%' => $this->fullName($sanction->getStudent()),
            '%group%'   => $sanction->getGroup()->getName(),
        ];

        foreach ($tasksByTeacher as $teacherId => $teacherTasks) {
            if ($this->usesDigest($centre, $teachersById[$teacherId])) {
                foreach ($teacherTasks as $task) {
                    $this->queueDigestItem($centre, $teachersById[$teacherId], 'sanction_task_assigned', $params + [
                        '%subject%' => $task->getGroupTeacher()->getSubject(),
                    ], $this->urlGenerator->generate(
                        'app_sanction_tasks_edit',
                        ['id' => $sanction->getId()->toRfc4122(), 'taskId' => $task->getId()->toRfc4122()],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ));
                }

                continue;
            }

            $rows = array_map(
                fn (SanctionTask $task): array => [
                    'subject' => $task->getGroupTeacher()->getSubject(),
                    'url'     => $this->urlGenerator->generate(
                        'app_sanction_tasks_edit',
                        ['id' => $sanction->getId()->toRfc4122(), 'taskId' => $task->getId()->toRfc4122()],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                ],
                $teacherTasks,
            );

            $this->dispatch(
                $centre,
                $teachersById[$teacherId],
                'sanction_task_assigned',
                $params + ['%count%' => count($teacherTasks)],
                'email/sanction_task_assigned.html.twig',
                ['rows' => $rows],
            );
        }
    }

    /**
     * Sends a single daily digest email to a teacher listing their pending sanction tasks
     * whose sanction is about to start. Eligibility (which teachers, which tasks) is already
     * resolved by the caller ({@see \App\MessageHandler\SanctionTaskReminderHandler}); this
     * method only formats and sends.
     *
     * @param list<SanctionTask> $items
     */
    public function sanctionTasksReminder(Teacher $teacher, array $items): void
    {
        if ($items === []) {
            return;
        }

        $centre = $this->centreForGroup($items[0]->getSanction()->getGroup());
        $now    = $this->clock->now();

        foreach ($items as $task) {
            $effectiveFrom = $task->getSanction()->getEffectiveFrom();
            \assert($effectiveFrom !== null);

            $this->queueDigestItem($centre, $teacher, 'sanction_task_reminder', [
                '%subject%' => $task->getGroupTeacher()->getSubject(),
                '%student%' => $this->fullName($task->getSanction()->getStudent()),
                '%count%'   => (int) $now->diff($effectiveFrom)->days,
            ], $this->urlGenerator->generate(
                'app_sanction_tasks_edit',
                ['id' => $task->getSanction()->getId()->toRfc4122(), 'taskId' => $task->getId()->toRfc4122()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ));
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

        $attachment = $this->reportPdfAttachment($report, $centre);

        foreach ($recipients as $teacher) {
            $this->dispatch($centre, $teacher, "report_$event", $params, 'email/incident_report_notice.html.twig', [
                'report'    => $report,
                'reportUrl' => $url,
            ], $attachment);
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

        $attachment = $this->reportPdfAttachment($report, $centre);

        foreach ($centre->getCommitteeMembers() as $member) {
            $this->dispatch($centre, $member, 'report_sanctionable_committee', $params, 'email/incident_report_notice.html.twig', [
                'report'    => $report,
                'reportUrl' => $url,
            ], $attachment);
        }
    }

    /**
     * Un estudiante ha alcanzado o superado, por primera vez, el número de ocurrencias de un tipo
     * de nota diaria activa que da lugar a un aviso de parte — avisa a quien indique el ajuste
     * "Nota que implica registro de parte" (nadie / tutor de grupo / equipo directivo / ambos),
     * con el detalle completo de las notas activas de ese tipo para ese estudiante.
     *
     * @param list<DailyNote> $notes
     */
    public function dailyNoteThresholdReached(DailyNoteType $type, Student $student, Group $group, array $notes): void
    {
        $centre = $group->getAcademicYear()->getEducationalCentre();
        $choice = $this->choiceFor('notifications.email_daily_note_threshold', $centre);
        if ($choice === 'none') {
            return;
        }

        /** @var array<string, Teacher> $recipients */
        $recipients = [];
        if ($choice === 'group_tutor' || $choice === 'both') {
            foreach ($group->getTutors() as $tutor) {
                $recipients[$tutor->getId()->toRfc4122()] = $tutor;
            }
        }
        if ($choice === 'admin' || $choice === 'both') {
            foreach ($centre->getAdmins() as $admin) {
                $recipients[$admin->getId()->toRfc4122()] = $admin;
            }
        }
        if ($recipients === []) {
            return;
        }

        $url = $this->urlGenerator->generate('app_students_show', ['id' => $student->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL);

        $params = [
            '%student%' => $this->fullName($student),
            '%group%'   => $group->getName(),
            '%type%'    => $type->getName(),
            '%count%'   => $type->getOccurrencesForReport(),
        ];

        foreach (array_values($recipients) as $teacher) {
            $this->dispatch($centre, $teacher, 'daily_note_threshold', $params, 'email/daily_note_threshold.html.twig', [
                'student'    => $student,
                'studentUrl' => $url,
                'notes'      => $notes,
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
    private function dispatch(EducationalCentre $centre, Teacher $teacher, string $eventKey, array $params, string $template, array $context, ?DataPart $attachment = null): void
    {
        $email = $teacher->getEmail();
        if ($email === null) {
            return;
        }

        // Recipients who chose the daily digest get this event as a line of their next digest instead.
        if ($this->usesDigest($centre, $teacher)) {
            $url = $context['reportUrl'] ?? $context['sanctionUrl'] ?? $context['studentUrl'] ?? null;
            $this->queueDigestItem($centre, $teacher, $eventKey, $params, is_string($url) ? $url : null);

            return;
        }

        $subject = $this->withSubjectPrefix($centre, $this->translator->trans("emails.$eventKey.subject", $params, 'emails'));

        $message = (new TemplatedEmail())
            ->to(new Address($email, $this->fullName($teacher)))
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context + [
                'teacher'     => $teacher,
                'params'      => $params,
                'transPrefix' => "emails.$eventKey",
            ]);

        if ($attachment !== null) {
            $message->addPart($attachment);
        }

        $this->send($centre, $teacher, $eventKey, $message);
    }

    /** Whether the teacher receives the centre's notifications as one daily digest (setting "notifications.email_delivery"). */
    private function usesDigest(EducationalCentre $centre, Teacher $teacher): bool
    {
        return $this->settings->getForTeacherInCentre('notifications.email_delivery', $teacher, $centre) === 'daily_digest';
    }

    /**
     * Queues one line for the teacher's daily digest ({@see EmailDigestSender}). The translation key of the
     * line is "emails.digest.line.<eventKey>" and uses these parameters.
     *
     * @param array<string, scalar|null> $params
     */
    private function queueDigestItem(EducationalCentre $centre, Teacher $teacher, string $eventKey, array $params, ?string $url): void
    {
        if ($teacher->getEmail() === null) {
            return;
        }

        $this->em->persist(new EmailDigestItem($centre, $teacher, $eventKey, $params, $url, $this->clock->now()));
        $this->em->flush();
    }

    /**
     * Sends one digest email with the given lines (all of the same teacher and centre), grouped by
     * section, and records it in the email log. Returns false if the transport failed.
     *
     * @param list<EmailDigestItem> $items
     */
    public function sendDigest(Teacher $teacher, EducationalCentre $centre, array $items): bool
    {
        $address = $teacher->getEmail();
        if ($address === null || $items === []) {
            return true;
        }

        /** @var array<string, array<string, array{text: string, url: ?string, times: int}>> $sections */
        $sections = [];
        foreach ($items as $item) {
            $section = EmailDigestSections::forEvent($item->getEventKey());
            $text    = $this->translator->trans('emails.digest.line.' . $item->getEventKey(), $item->getParams(), 'emails');
            $key     = $text . '|' . ($item->getUrl() ?? '');
            if (isset($sections[$section][$key])) {
                ++$sections[$section][$key]['times'];
            } else {
                $sections[$section][$key] = ['text' => $text, 'url' => $item->getUrl(), 'times' => 1];
            }
        }

        $ordered = [];
        foreach (EmailDigestSections::ORDER as $section) {
            if (isset($sections[$section])) {
                $ordered[] = [
                    'title' => $this->translator->trans('emails.digest.section.' . $section, [], 'emails'),
                    'lines' => array_values($sections[$section]),
                ];
            }
        }

        $count   = array_sum(array_map(static fn (array $s): int => count($s['lines']), $ordered));
        $params  = ['%count%' => $count, '%centre%' => $centre->getName()];
        $subject = $this->withSubjectPrefix($centre, $this->translator->trans('emails.daily_digest.subject', $params, 'emails'));

        $message = (new TemplatedEmail())
            ->to(new Address($address, $this->fullName($teacher)))
            ->subject($subject)
            ->htmlTemplate('email/daily_digest.html.twig')
            ->context([
                'teacher'     => $teacher,
                'params'      => $params,
                'transPrefix' => 'emails.daily_digest',
                'sections'    => $ordered,
            ]);

        return $this->send($centre, $teacher, 'daily_digest', $message);
    }

    private function withSubjectPrefix(EducationalCentre $centre, string $subject): string
    {
        $raw    = $this->settings->getForCentre('notifications.email_subject_prefix', $centre);
        $prefix = is_string($raw) ? trim($raw) : '';

        return $prefix === '' ? $subject : $prefix . ' ' . $subject;
    }

    /** Builds the report's PDF as an email attachment, or null if the setting is disabled. */
    private function reportPdfAttachment(IncidentReport $report, EducationalCentre $centre): ?DataPart
    {
        if ($this->settings->getForCentre('notifications.email_report_attach_pdf', $centre) !== true) {
            return null;
        }

        $placeholders = [
            'title'         => $this->translator->trans('pdf.incident_report.title', [], 'admin'),
            'report_nr'     => $report->getNumber(),
            'student_name'  => $this->fullName($report->getStudent()),
            'group_name'    => $report->getGroup()->getName(),
            'centre_name'   => $centre->getName(),
            'academic_year' => $report->getAcademicYear()->getName(),
        ];

        $header = $this->pdfHeaderBuilder->build('incident', $centre, $placeholders);
        $footer = $this->pdfHeaderBuilder->buildFooter('incident', $centre, $placeholders);

        $filename = sprintf('parte-%d.pdf', $report->getNumber());

        $response = $this->pdfRenderer->render(
            'pdf/incident_report.html.twig',
            [
                'centre'       => $centre,
                'report'       => $report,
                'observations' => $this->observations->findByIncidentReport($report),
                'history'      => $this->communications->findByIncidentReport($report),
                'footerHtml'   => $footer,
            ],
            $this->translator->trans('incident.show_ref', ['%number%' => $report->getNumber()], 'admin'),
            $filename,
            header: $header,
            draftWatermark: !$report->isNotified(),
            centre: $centre,
            reportType: 'incident',
        );

        $content = $response->getContent();
        \assert(is_string($content));

        return new DataPart($content, $filename, 'application/pdf');
    }

    /** Builds the sanction's PDF as an email attachment, or null if the setting is disabled. */
    private function sanctionPdfAttachment(Sanction $sanction, EducationalCentre $centre): ?DataPart
    {
        if ($this->settings->getForCentre('notifications.email_sanction_attach_pdf', $centre) !== true) {
            return null;
        }

        $reports = $sanction->getReports()->toArray();

        $placeholders = [
            'title'         => $this->translator->trans('pdf.sanction.title', [], 'admin'),
            'student_name'  => $this->fullName($sanction->getStudent()),
            'group_name'    => $sanction->getGroup()->getName(),
            'centre_name'   => $centre->getName(),
            'academic_year' => $sanction->getAcademicYear()->getName(),
        ];

        $header = $this->pdfHeaderBuilder->build('sanction', $centre, $placeholders);
        $footer = $this->pdfHeaderBuilder->buildFooter('sanction', $centre, $placeholders);

        $filename = sprintf('sancion-%s.pdf', substr($sanction->getId()->toRfc4122(), 0, 8));

        $response = $this->pdfRenderer->render(
            'pdf/sanction.html.twig',
            [
                'centre'                 => $centre,
                'sanction'               => $sanction,
                'history'                => $this->communications->findBySanction($sanction),
                'observations'           => $this->sanctionObservations->findBySanction($sanction),
                'observationsByReport'   => $this->observations->findByIncidentReports($reports),
                'communicationsByReport' => $this->communications->findByIncidentReports($reports),
                'footerHtml'             => $footer,
            ],
            $this->translator->trans('sanction.show_title', [], 'admin')
                . ' — ' . $sanction->getStudent()->getName()->getLastName() . ', ' . $sanction->getStudent()->getName()->getFirstName(),
            $filename,
            header: $header,
            draftWatermark: !$sanction->isNotified(),
            centre: $centre,
            reportType: 'sanction',
        );

        $content = $response->getContent();
        \assert(is_string($content));

        return new DataPart($content, $filename, 'application/pdf');
    }

    private function send(EducationalCentre $centre, Teacher $recipient, string $eventKey, TemplatedEmail $email): bool
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

        return $success;
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
            $this->clock->now(),
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
        return $group->getAcademicYear()->getEducationalCentre();
    }

    private function fullName(Teacher|Student $person): string
    {
        return $person->getName()->getFirstName() . ' ' . $person->getName()->getLastName();
    }
}
