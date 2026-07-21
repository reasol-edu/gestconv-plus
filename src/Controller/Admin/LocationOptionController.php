<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\LocationOption;
use App\Repository\EducationalCentreRepository;
use App\Repository\LocationOptionCategoryRepository;
use App\Repository\LocationOptionRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use App\Service\EntityChangeTracker;
use App\Service\LocationOptionExporter;
use App\Service\LocationOptionImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/ubicaciones')]
class LocationOptionController extends AbstractController
{
    use TranslatorTrait;

    /** @var list<string> */
    private const LOGGED_FIELDS = ['name', 'active'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly LocationOptionCategoryRepository $categories,
        private readonly LocationOptionRepository $options,
        private readonly LocationOptionExporter $exporter,
        private readonly LocationOptionImporter $importer,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
        private readonly EntityChangeTracker $changeTracker,
    ) {}

    #[Route('', name: 'app_centre_location_options_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $categories        = $this->categories->findByCentreOrdered($centre);
        $optionsByCategory = [];
        foreach ($categories as $cat) {
            $optionsByCategory[$cat->getId()->toRfc4122()] = $this->options->findByCategoryOrdered($cat);
        }

        return $this->render('admin/location_option/index.html.twig', [
            'centre'             => $centre,
            'categories'         => $categories,
            'optionsByCategory'  => $optionsByCategory,
        ]);
    }

    #[Route('/export', name: 'app_centre_location_options_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $data     = $this->exporter->export($centre);
        $filename = 'ubicaciones-' . $centre->getCode() . '.json';
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], true);
    }

    #[Route('/import', name: 'app_centre_location_options_import')]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/location_option/import.html.twig', ['centre' => $centre]);
        }

        if (!$this->isCsrfTokenValid('import_locations', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('json');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('location.import.error.no_file'));

            return $this->render('admin/location_option/import.html.twig', ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $this->addFlash('error', $this->t('location.import.error.invalid_json'));

            return $this->render('admin/location_option/import.html.twig', ['centre' => $centre]);
        }

        /** @var array<string, mixed> $decoded */
        $stats = $this->importer->import($decoded, $centre, $request->request->has('replace_existing'));

        $this->addFlash('success', $this->translator->trans('location.import.flash.summary', [
            '%categories%' => $stats['categories'],
            '%options%'    => $stats['options'],
        ], 'admin'));

        return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
    }

    #[Route('/nueva', name: 'app_centre_location_options_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('new_location_' . $centreId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name       = trim($request->request->getString('name'));
        $categoryId = $request->request->getString('category_id');
        $category   = $this->categories->findById($categoryId);

        if ($name === '' || $category === null || $category->getEducationalCentre() !== $centre) {
            $this->addFlash('error', $this->t('location.flash.invalid'));

            return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
        }

        $position = $this->options->countByCategory($category);

        $option = (new LocationOption())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition($position)
            ->setActive(true);

        $this->em->persist($option);
        $this->em->flush();

        $this->activityLog->log('location_option.created', [
            'entityId'   => $option->getId()->toRfc4122(),
            'categoryId' => $category->getId()->toRfc4122(),
            'name'       => $option->getName(),
        ]);

        $this->addFlash('success', $this->t('location.flash.created'));

        return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/editar', name: 'app_centre_location_options_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        $option = $this->options->findById($id);
        if ($option === null || $option->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_location_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $name       = trim($request->request->getString('name'));
            $categoryId = $request->request->getString('category_id');
            $active     = $request->request->getBoolean('active');
            $category   = $this->categories->findById($categoryId);

            if ($name !== '' && $category !== null && $category->getEducationalCentre() === $centre) {
                $before           = $this->changeTracker->snapshot($option, self::LOGGED_FIELDS);
                $categoryIdBefore = $option->getCategory()->getId()->toRfc4122();

                $option->setName($name)->setCategory($category)->setActive($active);
                $this->em->flush();

                $changes = $this->changeTracker->diff($before, $option, self::LOGGED_FIELDS);

                $categoryIdAfter = $option->getCategory()->getId()->toRfc4122();
                if ($categoryIdBefore !== $categoryIdAfter) {
                    $changes['categoryId'] = ['before' => $categoryIdBefore, 'after' => $categoryIdAfter];
                }

                if ($changes !== []) {
                    $this->activityLog->log('location_option.updated', [
                        'entityId' => $option->getId()->toRfc4122(),
                        'changes'  => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('location.flash.updated'));
            }

            return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
        }

        $categories = $this->categories->findByCentreOrdered($centre);

        return $this->render('admin/location_option/edit.html.twig', [
            'centre'     => $centre,
            'option'     => $option,
            'categories' => $categories,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_centre_location_options_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('delete_location_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $option = $this->options->findById($id);
        if ($option === null || $option->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $category = $option->getCategory();
        $entityId = $option->getId()->toRfc4122();
        $name     = $option->getName();

        $this->em->remove($option);
        $this->em->flush();

        foreach ($this->options->findByCategoryOrdered($category) as $pos => $o) {
            $o->setPosition($pos);
        }
        $this->em->flush();

        $this->activityLog->log('location_option.deleted', [
            'entityId' => $entityId,
            'name'     => $name,
        ]);

        $this->addFlash('success', $this->t('location.flash.deleted'));

        return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/subir', name: 'app_centre_location_options_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('move_location_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $option = $this->options->findById($id);
        if ($option === null || $option->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $siblings = $this->options->findByCategoryOrdered($option->getCategory());
        foreach ($siblings as $i => $o) {
            if ($o->getId()->toRfc4122() === $id && $i > 0) {
                $prev = $siblings[$i - 1];
                $posO = $o->getPosition();
                $o->setPosition($prev->getPosition());
                $prev->setPosition($posO);
                $this->em->flush();
                break;
            }
        }

        $this->addFlash('success', $this->t('location.flash.moved'));

        return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/bajar', name: 'app_centre_location_options_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('move_location_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $option = $this->options->findById($id);
        if ($option === null || $option->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $siblings = $this->options->findByCategoryOrdered($option->getCategory());
        $count    = count($siblings);
        foreach ($siblings as $i => $o) {
            if ($o->getId()->toRfc4122() === $id && $i < $count - 1) {
                $next = $siblings[$i + 1];
                $posO = $o->getPosition();
                $o->setPosition($next->getPosition());
                $next->setPosition($posO);
                $this->em->flush();
                break;
            }
        }

        $this->addFlash('success', $this->t('location.flash.moved'));

        return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/activar', name: 'app_centre_location_options_toggle_active', methods: ['POST'])]
    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        if (!$this->isCsrfTokenValid('toggle_location_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $option = $this->options->findById($id);
        if ($option === null || $option->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        $option->setActive(!$option->isActive());
        $this->em->flush();

        $this->addFlash('success', $this->t('location.flash.toggled'));

        return $this->redirectToRoute('app_centre_location_options_index', ['centreId' => $centreId]);
    }
}
