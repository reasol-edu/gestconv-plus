<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IncidentBehavior;
use App\Repository\EducationalCentreRepository;
use App\Repository\IncidentBehaviorCategoryRepository;
use App\Repository\IncidentBehaviorRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\IncidentBehaviorExporter;
use App\Service\IncidentBehaviorImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly IncidentBehaviorCategoryRepository $categories,
        private readonly IncidentBehaviorRepository $behaviors,
        private readonly IncidentBehaviorExporter $exporter,
        private readonly IncidentBehaviorImporter $importer,
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

        $categories          = $this->categories->findByCentreOrdered($centre);
        $behaviorsByCategory = [];
        foreach ($categories as $cat) {
            $behaviorsByCategory[$cat->getId()->toRfc4122()] = $this->behaviors->findByCategoryOrdered($cat);
        }

        return $this->render('admin/incident_behavior/index.html.twig', [
            'centre'             => $centre,
            'categories'         => $categories,
            'behaviorsByCategory' => $behaviorsByCategory,
        ]);
    }

    #[Route('/export', name: 'app_admin_incident_behaviors_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $data     = $this->exporter->export($centre);
        $filename = 'conductas-' . $centre->getCode() . '.json';
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], true);
    }

    #[Route('/import', name: 'app_admin_incident_behaviors_import')]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/incident_behavior/import.html.twig', ['centre' => $centre]);
        }

        if (!$this->isCsrfTokenValid('import_behaviors', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('json');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('behavior.import.error.no_file'));

            return $this->render('admin/incident_behavior/import.html.twig', ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $this->addFlash('error', $this->t('behavior.import.error.invalid_json'));

            return $this->render('admin/incident_behavior/import.html.twig', ['centre' => $centre]);
        }

        /** @var array<string, mixed> $decoded */
        $stats = $this->importer->import($decoded, $centre, $request->request->has('replace_existing'));

        $this->addFlash('success', $this->translator->trans('behavior.import.flash.summary', [
            '%categories%' => $stats['categories'],
            '%behaviors%'  => $stats['behaviors'],
        ], 'admin'));

        return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
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

        $name       = trim($request->request->getString('name'));
        $categoryId = $request->request->getString('category_id');
        $category   = $this->categories->findById($categoryId);

        if ($name === '' || $category === null || $category->getEducationalCentre() !== $centre) {
            $this->addFlash('error', $this->t('behavior.flash.invalid'));

            return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
        }

        $position = $this->behaviors->countByCategory($category);

        $behavior = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition($position)
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

            $name       = trim($request->request->getString('name'));
            $categoryId = $request->request->getString('category_id');
            $active     = $request->request->getBoolean('active');
            $category   = $this->categories->findById($categoryId);

            if ($name !== '' && $category !== null && $category->getEducationalCentre() === $centre) {
                $behavior->setName($name)->setCategory($category)->setActive($active);
                $this->em->flush();
                $this->addFlash('success', $this->t('behavior.flash.updated'));
            }

            return $this->redirectToRoute('app_admin_incident_behaviors_index', ['centreId' => $centreId]);
        }

        $categories = $this->categories->findByCentreOrdered($centre);

        return $this->render('admin/incident_behavior/edit.html.twig', [
            'centre'     => $centre,
            'behavior'   => $behavior,
            'categories' => $categories,
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

        $category = $behavior->getCategory();
        $this->em->remove($behavior);
        $this->em->flush();

        foreach ($this->behaviors->findByCategoryOrdered($category) as $pos => $b) {
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

        $behavior = $this->behaviors->findById($id);
        if ($behavior === null || $behavior->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $siblings = $this->behaviors->findByCategoryOrdered($behavior->getCategory());
        foreach ($siblings as $i => $b) {
            if ($b->getId()->toRfc4122() === $id && $i > 0) {
                $prev = $siblings[$i - 1];
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

        $behavior = $this->behaviors->findById($id);
        if ($behavior === null || $behavior->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $siblings = $this->behaviors->findByCategoryOrdered($behavior->getCategory());
        $count    = count($siblings);
        foreach ($siblings as $i => $b) {
            if ($b->getId()->toRfc4122() === $id && $i < $count - 1) {
                $next = $siblings[$i + 1];
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

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
