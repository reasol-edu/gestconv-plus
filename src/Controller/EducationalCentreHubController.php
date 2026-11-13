<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;
use App\Entity\SetupStepConfirmation;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\SetupStepConfirmationRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\CourseSetupChecklist;
use App\Service\SetupStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/centro/{centreId}')]
class EducationalCentreHubController extends AbstractController
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly CourseSetupChecklist $setupChecklist,
        private readonly SetupStepConfirmationRepository $setupConfirmations,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_centre_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('educational_centre/index.html.twig', [
            'centre'         => $centre,
            'setupChecklist' => $this->setupChecklist->build($centre),
        ]);
    }

    #[Route('/ajustes', name: 'app_centre_settings')]
    public function settings(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('educational_centre/settings.html.twig', [
            'centre' => $centre,
        ]);
    }

    /** Lista de comprobación de inicio de curso: los pasos para dejar listo el curso activo y cuáles están hechos. */
    #[Route('/inicio-de-curso', name: 'app_centre_setup', methods: ['GET'])]
    public function setup(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('educational_centre/setup.html.twig', [
            'centre'    => $centre,
            'checklist' => $this->setupChecklist->build($centre),
        ]);
    }

    /** Da por revisado a mano un paso que no se puede detectar (catálogos, ajustes). */
    #[Route('/inicio-de-curso/{step}/confirmar', name: 'app_centre_setup_confirm', methods: ['POST'])]
    public function confirmStep(string $centreId, string $step, Request $request): Response
    {
        [$centre, $stepCase] = $this->manualStepRequest($centreId, $step, $request);
        $year                = $centre->getActiveAcademicYear();
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        if ($this->setupConfirmations->findOne($year, $stepCase->value) === null) {
            $user = $this->getUser();
            $this->em->persist(new SetupStepConfirmation($year, $stepCase->value, $user instanceof Teacher ? $user : null));
            $this->em->flush();
        }

        return $this->redirectToRoute('app_centre_setup', ['centreId' => $centreId]);
    }

    /** Vuelve a dejar pendiente un paso confirmado a mano. */
    #[Route('/inicio-de-curso/{step}/desmarcar', name: 'app_centre_setup_unconfirm', methods: ['POST'])]
    public function unconfirmStep(string $centreId, string $step, Request $request): Response
    {
        [$centre, $stepCase] = $this->manualStepRequest($centreId, $step, $request);
        $year                = $centre->getActiveAcademicYear();
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $existing = $this->setupConfirmations->findOne($year, $stepCase->value);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        return $this->redirectToRoute('app_centre_setup', ['centreId' => $centreId]);
    }

    /**
     * Valida acceso, paso y CSRF de las acciones sobre un paso manual.
     *
     * @return array{0: EducationalCentre, 1: SetupStep}
     */
    private function manualStepRequest(string $centreId, string $step, Request $request): array
    {
        $centre   = $this->requireCentre($centreId);
        $stepCase = SetupStep::tryFrom($step);
        if ($stepCase === null || !$stepCase->isManual()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('setup_step_' . $stepCase->value, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return [$centre, $stepCase];
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findById($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }
}
