<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Repository\CommunicationMethodRepository;
use App\Repository\CommunicationRepository;
use App\Repository\IncidentReportObservationRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use App\Repository\StudentRepository;
use App\Security\Voter\IncidentReportVoter;
use App\Security\Voter\SanctionVoter;
use App\Service\ActivityLogService;
use App\Service\AppSettingsInterface;
use App\Service\IncidentEmailNotifier;
use App\Service\StudentContactVisibility;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/notificaciones')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly IncidentReportRepository $reports,
        private readonly IncidentReportObservationRepository $observations,
        private readonly SanctionRepository $sanctions,
        private readonly CommunicationRepository $communications,
        private readonly CommunicationMethodRepository $methods,
        private readonly StudentRepository $students,
        private readonly AppSettingsInterface $settings,
        private readonly StudentContactVisibility $contactVisibility,
        private readonly IncidentEmailNotifier $notifier,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
    ) {}

    #[Route('', name: 'app_notifications_index')]
    public function index(Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $tab = $request->query->getString('tab', 'pending') === 'history' ? 'history' : 'pending';

        return $this->render('notification/index.html.twig', [
            'centre' => $centre,
            'tab'    => $tab,
        ]);
    }

    #[Route('/partes/{id}/registrar', name: 'app_notifications_register_report', methods: ['GET', 'POST'])]
    public function registerForReport(string $id, Request $request): Response
    {
        $report = $this->reports->findById($id);
        if ($report === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(IncidentReportVoter::NOTIFY, $report);

        $centre = $this->centreFor($report);
        $user   = $this->getUser();
        \assert($user instanceof Teacher);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register_communication_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            if ($this->registerCommunication($report, $centre, $user, $request)) {
                $this->addFlash('success', $this->t('notification.flash.registered'));

                return $this->redirectToRoute('app_notifications_index');
            }

            $this->addFlash('error', $this->t('notification.flash.invalid'));

            return $this->redirectToRoute('app_notifications_register_report', ['id' => $id]);
        }

        return $this->render('notification/register_report.html.twig', [
            'centre'        => $centre,
            'report'        => $report,
            'observations'  => $this->observations->findByIncidentReport($report),
            'history'       => $this->communications->findByIncidentReport($report),
            'methods'       => $this->methods->findActiveByCentreOrdered($centre),
            'canSeeContact' => $report->getRegisteredBy() === $user
                || $this->contactVisibility->isVisibleTo($user, $centre, $report->getStudent()),
        ]);
    }

    #[Route('/sanciones/{id}/registrar', name: 'app_notifications_register_sanction', methods: ['GET', 'POST'])]
    public function registerForSanction(string $id, Request $request): Response
    {
        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(SanctionVoter::NOTIFY, $sanction);

        $centre = $this->centreFor($sanction);
        $user   = $this->getUser();
        \assert($user instanceof Teacher);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register_communication_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            if ($this->registerCommunication($sanction, $centre, $user, $request)) {
                $this->addFlash('success', $this->t('notification.flash.registered'));

                return $this->redirectToRoute('app_notifications_index');
            }

            $this->addFlash('error', $this->t('notification.flash.invalid'));

            return $this->redirectToRoute('app_notifications_register_sanction', ['id' => $id]);
        }

        return $this->render('notification/register_sanction.html.twig', [
            'centre'        => $centre,
            'sanction'      => $sanction,
            'history'       => $this->communications->findBySanction($sanction),
            'methods'       => $this->methods->findActiveByCentreOrdered($centre),
            'canSeeContact' => $sanction->getRegisteredBy() === $user
                || $this->contactVisibility->isVisibleTo($user, $centre, $sanction->getStudent()),
        ]);
    }

    #[Route('/partes/estudiante/{studentId}/registrar', name: 'app_notifications_register_student_reports', methods: ['GET', 'POST'])]
    public function registerForStudentReports(string $studentId, Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $student = $this->students->findById($studentId);
        if ($student === null) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $notifierSetting = $this->reportNotifierSetting($centre);
        $year            = $this->tenantContext->getViewYear($centre);
        $reports         = $this->reports->createNotifiableQuery($centre, $user, $notifierSetting, $student, $year)->getResult();

        if ($reports === []) {
            $this->addFlash('error', $this->t('notification.flash.no_notifiable_reports'));

            return $this->redirectToRoute('app_notifications_index');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register_student_reports_' . $studentId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            /** @var array<string, IncidentReport> $permittedById */
            $permittedById = [];
            foreach ($reports as $report) {
                $permittedById[$report->getId()->toRfc4122()] = $report;
            }

            $selected = [];
            foreach ($request->request->all('report_ids') as $id) {
                if (is_string($id) && isset($permittedById[$id])) {
                    $selected[] = $permittedById[$id];
                }
            }

            $input = $selected !== [] ? $this->parseCommunicationInput($centre, $request) : null;

            if ($selected === [] || $input === null) {
                $this->addFlash('error', $this->t('notification.flash.invalid'));

                return $this->redirectToRoute('app_notifications_register_student_reports', ['studentId' => $studentId]);
            }

            /** @var list<IncidentReport> $newlyNotified */
            $newlyNotified = [];

            /** @var list<array{Communication, IncidentReport}> $createdCommunications */
            $createdCommunications = [];

            foreach ($selected as $report) {
                $communication = Communication::forIncidentReport(
                    $report,
                    $input['method'],
                    $user,
                    $input['performedAt'],
                    $input['result'],
                    $input['description'],
                );
                $this->em->persist($communication);
                $createdCommunications[] = [$communication, $report];

                if ($input['result'] === CommunicationResult::Notified && !$report->isNotified()) {
                    $report->setNotifiedCommunication($communication);
                    $newlyNotified[] = $report;
                }
            }

            $this->em->flush();

            foreach ($newlyNotified as $report) {
                $this->notifier->reportNotified($report, $user);
            }

            foreach ($createdCommunications as [$communication, $report]) {
                $this->activityLog->log('communication.registered', [
                    'entityId' => $communication->getId()->toRfc4122(),
                    'reportId' => $report->getId()->toRfc4122(),
                    'method'   => $input['method']->getName(),
                    'result'   => $input['result']->value,
                ]);
            }

            $this->addFlash('success', $this->t('notification.flash.registered'));

            return $this->redirectToRoute('app_notifications_index');
        }

        return $this->render('notification/register_student_reports.html.twig', [
            'centre'               => $centre,
            'student'              => $student,
            'reports'              => $reports,
            'observationsByReport' => $this->observations->findByIncidentReports($reports),
            'methods'              => $this->methods->findActiveByCentreOrdered($centre),
        ]);
    }

    private function reportNotifierSetting(EducationalCentre $centre): string
    {
        $setting = $this->settings->getForCentre('notifications.report_notifier', $centre);

        return is_string($setting) ? $setting : 'both';
    }

    private function registerCommunication(IncidentReport|Sanction $target, EducationalCentre $centre, Teacher $user, Request $request): bool
    {
        $input = $this->parseCommunicationInput($centre, $request);
        if ($input === null) {
            return false;
        }

        $communication = $target instanceof IncidentReport
            ? Communication::forIncidentReport($target, $input['method'], $user, $input['performedAt'], $input['result'], $input['description'])
            : Communication::forSanction($target, $input['method'], $user, $input['performedAt'], $input['result'], $input['description']);

        $this->em->persist($communication);

        $newlyNotified = $input['result'] === CommunicationResult::Notified && !$target->isNotified();
        if ($newlyNotified) {
            $target->setNotifiedCommunication($communication);
        }

        $this->em->flush();

        if ($newlyNotified) {
            if ($target instanceof IncidentReport) {
                $this->notifier->reportNotified($target, $user);
            } else {
                $this->notifier->sanctionNotified($target, $user);
            }
        }

        $this->activityLog->log('communication.registered', array_filter([
            'entityId'  => $communication->getId()->toRfc4122(),
            'reportId'  => $target instanceof IncidentReport ? $target->getId()->toRfc4122() : null,
            'sanctionId' => $target instanceof Sanction ? $target->getId()->toRfc4122() : null,
            'method'    => $input['method']->getName(),
            'result'    => $input['result']->value,
        ], static fn (mixed $v): bool => $v !== null));

        return true;
    }

    /**
     * @return array{method: \App\Entity\CommunicationMethod, performedAt: \DateTimeImmutable, result: CommunicationResult, description: ?string}|null
     */
    private function parseCommunicationInput(EducationalCentre $centre, Request $request): ?array
    {
        $methodId       = $request->request->getString('method_id');
        $performedAtRaw = trim($request->request->getString('performed_at'));
        $description    = trim($request->request->getString('description'));
        $result         = CommunicationResult::tryFrom($request->request->getString('result'));

        $method = $methodId !== '' ? $this->methods->findById($methodId) : null;
        if ($method === null || $method->getEducationalCentre() !== $centre || $performedAtRaw === '' || $result === null) {
            return null;
        }

        try {
            $performedAt = new \DateTimeImmutable($performedAtRaw);
        } catch (\Exception) {
            return null;
        }

        return [
            'method'      => $method,
            'performedAt' => $performedAt,
            'result'      => $result,
            'description' => $description !== '' ? $description : null,
        ];
    }

    private function centreFor(IncidentReport|Sanction $target): EducationalCentre
    {
        return $target->getGroup()
            ->getProgrammeYear()
            ->getProgramme()
            ->getAcademicYear()
            ->getEducationalCentre();
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'notifications');
    }
}
