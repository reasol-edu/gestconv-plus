<?php

namespace App\Controller;

use App\Entity\Teacher;
use App\Repository\StudentRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StudentRepository $studentRepository,
    ) {}

    #[Route('/', name: 'app_dashboard')]
    public function index(): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $year = $this->tenantContext->getViewYear($centre);

        if ($year === null) {
            return $this->render('dashboard/index.html.twig', [
                'studentCount' => 0,
            ]);
        }

        $user   = $this->getUser();
        $viewer = $user instanceof Teacher ? $user : null;

        return $this->render('dashboard/index.html.twig', [
            'studentCount' => $this->studentRepository->countByActiveYear($centre, $viewer, $year),
        ]);
    }
}
