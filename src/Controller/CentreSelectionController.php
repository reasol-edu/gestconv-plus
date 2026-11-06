<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Service\NonTeachingAccess;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class CentreSelectionController extends AbstractController
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TenantContext $tenantContext,
        private readonly NonTeachingAccess $nonTeaching,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/seleccion/centro', name: 'app_select_centre')]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $centres = $this->centres->findAccessibleByTeacher($user);

        // El personal no docente no entra en un centro sin el módulo de incidencias: se le explica el motivo.
        $blocked = [];
        if ($this->nonTeaching->isRestricted($user)) {
            foreach ($centres as $centre) {
                $reason = $this->nonTeaching->blockReason($centre);
                if ($reason !== null) {
                    $blocked[$centre->getId()->toRfc4122()] = $reason;
                }
            }
        }

        return $this->render('centre_selection/index.html.twig', [
            'centres' => $centres,
            'blocked' => $blocked,
            'errors'  => $request->getSession() instanceof \Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface
                ? $request->getSession()->getFlashBag()->get('error')
                : [],
        ]);
    }

    #[Route('/seleccion/centro/{id}', name: 'app_select_centre_choose', methods: ['POST'])]
    public function choose(string $id, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('select_centre_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $centre = $this->centres->findById($id);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }

        $accessible = $this->centres->findAccessibleByTeacher($user);
        $ids = array_map(static fn($c) => $c->getId()->toRfc4122(), $accessible);

        if (!\in_array($id, $ids, strict: true)) {
            throw $this->createAccessDeniedException();
        }

        if ($this->nonTeaching->isRestricted($user) && ($reason = $this->nonTeaching->blockReason($centre)) !== null) {
            $this->addFlash('error', $this->translator->trans($reason, ['%centre%' => $centre->getName()], 'centre_selection'));

            return $this->redirectToRoute('app_select_centre');
        }

        $this->tenantContext->selectCentre($centre);

        return $this->redirectToRoute('app_dashboard');
    }
}
