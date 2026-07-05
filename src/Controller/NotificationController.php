<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Communication;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Repository\CommunicationMethodRepository;
use App\Repository\CommunicationRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionRepository;
use App\Security\Voter\IncidentReportVoter;
use App\Security\Voter\SanctionVoter;
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
        private readonly SanctionRepository $sanctions,
        private readonly CommunicationRepository $communications,
        private readonly CommunicationMethodRepository $methods,
        private readonly StudentContactVisibility $contactVisibility,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_notifications_index')]
    public function index(): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('notification/index.html.twig', [
            'centre'    => $centre,
            'reports'   => $this->reports->findPendingNotification($centre, $user),
            'sanctions' => $this->sanctions->findPendingNotification($centre, $user),
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

    private function registerCommunication(IncidentReport|Sanction $target, EducationalCentre $centre, Teacher $user, Request $request): bool
    {
        $methodId       = $request->request->getString('method_id');
        $performedAtRaw = trim($request->request->getString('performed_at'));
        $description    = trim($request->request->getString('description'));
        $result         = CommunicationResult::tryFrom($request->request->getString('result'));

        $method = $methodId !== '' ? $this->methods->findById($methodId) : null;
        if ($method === null || $method->getEducationalCentre() !== $centre || $performedAtRaw === '' || $result === null) {
            return false;
        }

        try {
            $performedAt = new \DateTimeImmutable($performedAtRaw);
        } catch (\Exception) {
            return false;
        }

        $communication = $target instanceof IncidentReport
            ? Communication::forIncidentReport($target, $method, $user, $performedAt, $result, $description !== '' ? $description : null)
            : Communication::forSanction($target, $method, $user, $performedAt, $result, $description !== '' ? $description : null);

        $this->em->persist($communication);

        if ($result === CommunicationResult::Notified && !$target->isNotified()) {
            $target->setNotifiedCommunication($communication);
        }

        $this->em->flush();

        return true;
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
