<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/mi-tutoria')]
class TutorshipController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly GroupRepository $groups,
    ) {}

    #[Route('', name: 'app_tutorship_index')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $viewer = $this->getUser();
        if (!$viewer instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null || !$this->groups->hasTutoredGroupsInYear($centre, $viewer, $year)) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('tutorship/index.html.twig', [
            'centre'  => $centre,
            'viewer'  => $viewer,
            'groupId' => $request->query->getString('groupId'),
        ]);
    }
}
