<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SanctionMeasure;
use App\Repository\EducationalCentreRepository;
use App\Repository\SanctionMeasureCategoryRepository;
use App\Repository\SanctionMeasureRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\SanctionMeasureExporter;
use App\Service\SanctionMeasureImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/centros/{centreId}/sanciones/medidas')]
#[IsGranted('ROLE_ADMIN')]
class SanctionMeasureController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly SanctionMeasureCategoryRepository $categories,
        private readonly SanctionMeasureRepository $measures,
        private readonly SanctionMeasureExporter $exporter,
        private readonly SanctionMeasureImporter $importer,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_admin_sanction_measures_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $categories        = $this->categories->findByCentreOrdered($centre);
        $measuresByCategory = [];
        foreach ($categories as $cat) {
            $measuresByCategory[$cat->getId()->toRfc4122()] = $this->measures->findByCategoryOrdered($cat);
        }

        return $this->render('admin/sanction_measure/index.html.twig', [
            'centre'             => $centre,
            'categories'         => $categories,
            'measuresByCategory' => $measuresByCategory,
        ]);
    }

    #[Route('/export', name: 'app_admin_sanction_measures_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $data     = $this->exporter->export($centre);
        $filename = 'medidas-' . $centre->getCode() . '.json';
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], true);
    }

    #[Route('/import', name: 'app_admin_sanction_measures_import')]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/sanction_measure/import.html.twig', ['centre' => $centre]);
        }

        if (!$this->isCsrfTokenValid('import_sanction_measures', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('json');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('sanction_measure.import.error.no_file'));

            return $this->render('admin/sanction_measure/import.html.twig', ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $this->addFlash('error', $this->t('sanction_measure.import.error.invalid_json'));

            return $this->render('admin/sanction_measure/import.html.twig', ['centre' => $centre]);
        }

        /** @var array<string, mixed> $decoded */
        $stats = $this->importer->import($decoded, $centre, $request->request->has('replace_existing'));

        $this->addFlash('success', $this->translator->trans('sanction_measure.import.flash.summary', [
            '%categories%' => $stats['categories'],
            '%measures%'   => $stats['measures'],
        ], 'admin'));

        return $this->redirectToRoute('app_admin_sanction_measures_index', ['centreId' => $centreId]);
    }

    #[Route('/nueva', name: 'app_admin_sanction_measures_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('new_sanction_measure_' . $centreId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name         = trim($request->request->getString('name'));
        $categoryId   = $request->request->getString('category_id');
        $hasDateRange = $request->request->getBoolean('has_date_range');
        $category     = $this->categories->findById($categoryId);

        if ($name === '' || $category === null || $category->getEducationalCentre() !== $centre) {
            $this->addFlash('error', $this->t('sanction_measure.flash.invalid'));

            return $this->redirectToRoute('app_admin_sanction_measures_index', ['centreId' => $centreId]);
        }

        $position = $this->measures->countByCategory($category);

        $measure = (new SanctionMeasure())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setHasDateRange($hasDateRange)
            ->setPosition($position)
            ->setActive(true);

        $this->em->persist($measure);
        $this->em->flush();

        $this->addFlash('success', $this->t('sanction_measure.flash.created'));

        return $this->redirectToRoute('app_admin_sanction_measures_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/editar', name: 'app_admin_sanction_measures_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $measure = $this->measures->findById($id);
        if ($measure === null || $measure->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_sanction_measure_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $name         = trim($request->request->getString('name'));
            $categoryId   = $request->request->getString('category_id');
            $hasDateRange = $request->request->getBoolean('has_date_range');
            $active       = $request->request->getBoolean('active');
            $category     = $this->categories->findById($categoryId);

            if ($name !== '' && $category !== null && $category->getEducationalCentre() === $centre) {
                $measure->setName($name)
                        ->setCategory($category)
                        ->setHasDateRange($hasDateRange)
                        ->setActive($active);
                $this->em->flush();
                $this->addFlash('success', $this->t('sanction_measure.flash.updated'));
            }

            return $this->redirectToRoute('app_admin_sanction_measures_index', ['centreId' => $centreId]);
        }

        $categories = $this->categories->findByCentreOrdered($centre);

        return $this->render('admin/sanction_measure/edit.html.twig', [
            'centre'     => $centre,
            'measure'    => $measure,
            'categories' => $categories,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_admin_sanction_measures_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('delete_sanction_measure_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $measure = $this->measures->findById($id);
        if ($measure === null || $measure->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $category = $measure->getCategory();
        $this->em->remove($measure);
        $this->em->flush();

        foreach ($this->measures->findByCategoryOrdered($category) as $pos => $m) {
            $m->setPosition($pos);
        }
        $this->em->flush();

        $this->addFlash('success', $this->t('sanction_measure.flash.deleted'));

        return $this->redirectToRoute('app_admin_sanction_measures_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/subir', name: 'app_admin_sanction_measures_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('move_sanction_measure_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $measure = $this->measures->findById($id);
        if ($measure === null || $measure->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $siblings = $this->measures->findByCategoryOrdered($measure->getCategory());
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

        $this->addFlash('success', $this->t('sanction_measure.flash.moved'));

        return $this->redirectToRoute('app_admin_sanction_measures_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/bajar', name: 'app_admin_sanction_measures_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('move_sanction_measure_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $measure = $this->measures->findById($id);
        if ($measure === null || $measure->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $siblings = $this->measures->findByCategoryOrdered($measure->getCategory());
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

        $this->addFlash('success', $this->t('sanction_measure.flash.moved'));

        return $this->redirectToRoute('app_admin_sanction_measures_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/activar', name: 'app_admin_sanction_measures_toggle_active', methods: ['POST'])]
    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('toggle_sanction_measure_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $measure = $this->measures->findById($id);
        if ($measure === null || $measure->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $measure->setActive(!$measure->isActive());
        $this->em->flush();

        $this->addFlash('success', $this->t('sanction_measure.flash.toggled'));

        return $this->redirectToRoute('app_admin_sanction_measures_index', ['centreId' => $centreId]);
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
