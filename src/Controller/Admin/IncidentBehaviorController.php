<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IncidentBehavior;
use App\Repository\EducationalCentreRepository;
use App\Repository\IncidentBehaviorRepository;
use App\Security\Voter\EducationalCentreVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/centros/{centreId}/conductas')]
#[IsGranted('ROLE_ADMIN')]
class IncidentBehaviorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly IncidentBehaviorRepository $behaviors,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_admin_incident_behaviors_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $this->render('admin/incident_behavior/index.html.twig', [
            'centre'    => $centre,
            'behaviors' => $this->behaviors->findByCentreOrdered($centre),
        ]);
    }

    #[Route('/nueva', name: 'app_admin_incident_behaviors_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('new_behavior_' . $centreId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name    = trim($request->request->getString('name'));
        $serious = $request->request->getBoolean('serious');

        if ($name === '') {
            $this->addFlash('error', $this->t('behavior.field.name') . ' ' . $this->t('field.required'));

            return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
        }

        $position = $this->behaviors->countByCentre($centre);

        $behavior = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position)
            ->setSerious($serious)
            ->setActive(true);

        $this->em->persist($behavior);
        $this->em->flush();

        $this->addFlash('success', $this->t('behavior.flash.created'));

        return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/editar', name: 'app_admin_incident_behaviors_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $behavior = $this->behaviors->findById($id);
        if ($behavior === null || $behavior->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_behavior_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $name    = trim($request->request->getString('name'));
            $serious = $request->request->getBoolean('serious');
            $active  = $request->request->getBoolean('active');

            if ($name !== '') {
                $behavior->setName($name)->setSerious($serious)->setActive($active);
                $this->em->flush();
                $this->addFlash('success', $this->t('behavior.flash.updated'));
            }

            return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
        }

        return $this->render('admin/incident_behavior/edit.html.twig', [
            'centre'   => $centre,
            'behavior' => $behavior,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_admin_incident_behaviors_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('delete_behavior_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $behavior = $this->behaviors->findById($id);
        if ($behavior === null || $behavior->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $this->em->remove($behavior);
        $this->em->flush();

        // Reorder remaining behaviors
        $remaining = $this->behaviors->findByCentreOrdered($centre);
        foreach ($remaining as $pos => $b) {
            $b->setPosition($pos);
        }
        $this->em->flush();

        $this->addFlash('success', $this->t('behavior.flash.deleted'));

        return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/subir', name: 'app_admin_incident_behaviors_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('move_behavior_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $all = $this->behaviors->findByCentreOrdered($centre);
        foreach ($all as $i => $b) {
            if ($b->getId()->toRfc4122() === $id && $i > 0) {
                $prev = $all[$i - 1];
                $posB = $b->getPosition();
                $b->setPosition($prev->getPosition());
                $prev->setPosition($posB);
                $this->em->flush();
                break;
            }
        }

        $this->addFlash('success', $this->t('behavior.flash.moved'));

        return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/bajar', name: 'app_admin_incident_behaviors_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('move_behavior_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $all   = $this->behaviors->findByCentreOrdered($centre);
        $count = count($all);
        foreach ($all as $i => $b) {
            if ($b->getId()->toRfc4122() === $id && $i < $count - 1) {
                $next = $all[$i + 1];
                $posB = $b->getPosition();
                $b->setPosition($next->getPosition());
                $next->setPosition($posB);
                $this->em->flush();
                break;
            }
        }

        $this->addFlash('success', $this->t('behavior.flash.moved'));

        return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/activar', name: 'app_admin_incident_behaviors_toggle_active', methods: ['POST'])]
    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('toggle_behavior_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $behavior = $this->behaviors->findById($id);
        if ($behavior === null || $behavior->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $behavior->setActive(!$behavior->isActive());
        $this->em->flush();

        $this->addFlash('success', $this->t('behavior.flash.toggled'));

        return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/tipo', name: 'app_admin_incident_behaviors_toggle_serious', methods: ['POST'])]
    public function toggleSerious(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('toggle_serious_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $behavior = $this->behaviors->findById($id);
        if ($behavior === null || $behavior->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $behavior->setSerious(!$behavior->isSerious());
        $this->em->flush();

        $this->addFlash('success', $this->t('behavior.flash.toggled'));

        return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
