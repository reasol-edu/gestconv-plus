<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Admin\Catalog\AbstractCatalogController;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\LocationOption;
use App\Repository\EducationalCentreRepository;
use App\Repository\LocationOptionCategoryRepository;
use App\Repository\LocationOptionRepository;
use App\Service\ActivityLogService;
use App\Service\EntityChangeTracker;
use App\Service\LocationOptionExporter;
use App\Service\LocationOptionImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/ubicaciones')]
class LocationOptionController extends AbstractCatalogController
{
    /** @var list<string> */
    private const LOGGED_FIELDS = ['name', 'active'];

    public function __construct(
        EntityManagerInterface $em,
        EducationalCentreRepository $centres,
        TranslatorInterface $translator,
        ActivityLogService $activityLog,
        private readonly LocationOptionCategoryRepository $categories,
        private readonly LocationOptionRepository $options,
        private readonly LocationOptionExporter $exporterService,
        private readonly LocationOptionImporter $importerService,
        private readonly EntityChangeTracker $changeTracker,
    ) {
        parent::__construct($em, $centres, $translator, $activityLog);
    }

    protected function catalogKey(): string
    {
        return 'location';
    }

    protected function logEventPrefix(): string
    {
        return 'location_option';
    }

    protected function indexRoute(): string
    {
        return 'app_centre_location_options_index';
    }

    protected function exportFilenamePrefix(): string
    {
        return 'ubicaciones';
    }

    protected function exporter(): LocationOptionExporter
    {
        return $this->exporterService;
    }

    protected function importer(): LocationOptionImporter
    {
        return $this->importerService;
    }

    protected function importTemplate(): string
    {
        return 'admin/location_option/import.html.twig';
    }

    protected function importFlashParams(array $stats): array
    {
        return [
            '%categories%' => $stats['categories'],
            '%options%'    => $stats['options'],
        ];
    }

    protected function findEntity(string $id): ?CatalogEntryInterface
    {
        return $this->options->findById($id);
    }

    protected function siblingsOf(CatalogEntryInterface $entity, EducationalCentre $centre): array
    {
        assert($entity instanceof LocationOption);

        return $this->options->findByCategoryOrdered($entity->getCategory());
    }

    #[Route('', name: 'app_centre_location_options_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        $categories        = $this->categories->findByCentreOrdered($centre);
        $optionsByCategory = [];
        foreach ($categories as $cat) {
            $optionsByCategory[$cat->getId()->toRfc4122()] = $this->options->findByCategoryOrdered($cat);
        }

        return $this->render('admin/location_option/index.html.twig', [
            'centre'            => $centre,
            'categories'        => $categories,
            'optionsByCategory' => $optionsByCategory,
        ]);
    }

    #[Route('/export', name: 'app_centre_location_options_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        return parent::export($centreId);
    }

    #[Route('/import', name: 'app_centre_location_options_import')]
    public function import(string $centreId, Request $request): Response
    {
        return parent::import($centreId, $request);
    }

    #[Route('/nueva', name: 'app_centre_location_options_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'new_location_' . $centreId);

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
        $centre = $this->requireCentre($centreId);

        $option = $this->options->findById($id);
        if ($option === null || $option->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'edit_location_' . $id);

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
        return parent::delete($centreId, $id, $request);
    }

    #[Route('/{id}/subir', name: 'app_centre_location_options_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        return parent::moveUp($centreId, $id, $request);
    }

    #[Route('/{id}/bajar', name: 'app_centre_location_options_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        return parent::moveDown($centreId, $id, $request);
    }

    #[Route('/{id}/activar', name: 'app_centre_location_options_toggle_active', methods: ['POST'])]
    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        return parent::toggleActive($centreId, $id, $request);
    }
}
