<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CommunicationMethod;
use App\Repository\CommunicationMethodRepository;
use App\Repository\CommunicationRepository;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centros/{centreId}/metodos-comunicacion')]
class CommunicationMethodController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly CommunicationMethodRepository $methods,
        private readonly CommunicationRepository $communications,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_admin_communication_methods_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $this->render('admin/communication_method/index.html.twig', [
            'centre'  => $centre,
            'methods' => $this->methods->findByCentreOrdered($centre),
        ]);
    }

    #[Route('/nuevo', name: 'app_admin_communication_methods_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('new_communication_method_' . $centreId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim($request->request->getString('name'));

        if ($name === '') {
            $this->addFlash('error', $this->t('communication_method.flash.invalid'));

            return $this->redirectToRoute('app_admin_communication_methods_index', ['centreId' => $centreId]);
        }

        $method = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($this->methods->countByCentre($centre))
            ->setActive(true);

        $this->em->persist($method);
        $this->em->flush();

        $this->addFlash('success', $this->t('communication_method.flash.created'));

        return $this->redirectToRoute('app_admin_communication_methods_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/editar', name: 'app_admin_communication_methods_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $method = $this->methods->findById($id);
        if ($method === null || $method->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_communication_method_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $name   = trim($request->request->getString('name'));
            $active = $request->request->getBoolean('active');

            if ($name !== '') {
                $method->setName($name)->setActive($active);
                $this->em->flush();
                $this->addFlash('success', $this->t('communication_method.flash.updated'));
            } else {
                $this->addFlash('error', $this->t('communication_method.flash.invalid'));
            }

            return $this->redirectToRoute('app_admin_communication_methods_index', ['centreId' => $centreId]);
        }

        return $this->render('admin/communication_method/edit.html.twig', [
            'centre' => $centre,
            'method' => $method,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_admin_communication_methods_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('delete_communication_method_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $method = $this->methods->findById($id);
        if ($method === null || $method->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($this->communications->countByMethod($method) > 0) {
            $this->addFlash('error', $this->t('communication_method.flash.in_use'));

            return $this->redirectToRoute('app_admin_communication_methods_index', ['centreId' => $centreId]);
        }

        $this->em->remove($method);
        $this->em->flush();

        foreach ($this->methods->findByCentreOrdered($centre) as $pos => $m) {
            $m->setPosition($pos);
        }
        $this->em->flush();

        $this->addFlash('success', $this->t('communication_method.flash.deleted'));

        return $this->redirectToRoute('app_admin_communication_methods_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/subir', name: 'app_admin_communication_methods_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('move_communication_method_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $method = $this->methods->findById($id);
        if ($method === null || $method->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $siblings = $this->methods->findByCentreOrdered($centre);
        foreach ($siblings as $i => $m) {
            if ($m->getId()->toRfc4122() === $id && $i > 0) {
                $prev = $siblings[$i - 1];
                $posM = $m->getPosition();
                $m->setPosition($prev->getPosition());
                $prev->setPosition($posM);
                $this->em->flush();
                break;
            }
        }

        $this->addFlash('success', $this->t('communication_method.flash.moved'));

        return $this->redirectToRoute('app_admin_communication_methods_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/bajar', name: 'app_admin_communication_methods_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('move_communication_method_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $method = $this->methods->findById($id);
        if ($method === null || $method->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $siblings = $this->methods->findByCentreOrdered($centre);
        $count    = count($siblings);
        foreach ($siblings as $i => $m) {
            if ($m->getId()->toRfc4122() === $id && $i < $count - 1) {
                $next = $siblings[$i + 1];
                $posM = $m->getPosition();
                $m->setPosition($next->getPosition());
                $next->setPosition($posM);
                $this->em->flush();
                break;
            }
        }

        $this->addFlash('success', $this->t('communication_method.flash.moved'));

        return $this->redirectToRoute('app_admin_communication_methods_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/activar', name: 'app_admin_communication_methods_toggle_active', methods: ['POST'])]
    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('toggle_communication_method_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $method = $this->methods->findById($id);
        if ($method === null || $method->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $method->setActive(!$method->isActive());
        $this->em->flush();

        $this->addFlash('success', $this->t('communication_method.flash.toggled'));

        return $this->redirectToRoute('app_admin_communication_methods_index', ['centreId' => $centreId]);
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
