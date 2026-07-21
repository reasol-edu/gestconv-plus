<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Admin\Catalog\AbstractCatalogController;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\SanctionMeasure;
use App\Repository\EducationalCentreRepository;
use App\Repository\SanctionMeasureCategoryRepository;
use App\Repository\SanctionMeasureRepository;
use App\Service\ActivityLogService;
use App\Service\EntityChangeTracker;
use App\Service\SanctionMeasureExporter;
use App\Service\SanctionMeasureImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/sanciones/medidas')]
class SanctionMeasureController extends AbstractCatalogController
{
    /** @var list<string> */
    private const LOGGED_FIELDS = ['name', 'hasDateRange', 'active'];

    public function __construct(
        EntityManagerInterface $em,
        EducationalCentreRepository $centres,
        TranslatorInterface $translator,
        ActivityLogService $activityLog,
        private readonly SanctionMeasureCategoryRepository $categories,
        private readonly SanctionMeasureRepository $measures,
        private readonly SanctionMeasureExporter $exporterService,
        private readonly SanctionMeasureImporter $importerService,
        private readonly EntityChangeTracker $changeTracker,
    ) {
        parent::__construct($em, $centres, $translator, $activityLog);
    }

    protected function catalogKey(): string
    {
        return 'sanction_measure';
    }

    protected function logEventPrefix(): string
    {
        return 'sanction_measure';
    }

    protected function indexRoute(): string
    {
        return 'app_centre_sanction_measures_index';
    }

    protected function exportFilenamePrefix(): string
    {
        return 'medidas';
    }

    protected function exporter(): SanctionMeasureExporter
    {
        return $this->exporterService;
    }

    protected function importer(): SanctionMeasureImporter
    {
        return $this->importerService;
    }

    protected function importTemplate(): string
    {
        return 'admin/sanction_measure/import.html.twig';
    }

    protected function importFlashParams(array $stats): array
    {
        return [
            '%categories%' => $stats['categories'],
            '%measures%'   => $stats['measures'],
        ];
    }

    protected function findEntity(string $id): ?CatalogEntryInterface
    {
        return $this->measures->findById($id);
    }

    protected function siblingsOf(CatalogEntryInterface $entity, EducationalCentre $centre): array
    {
        assert($entity instanceof SanctionMeasure);

        return $this->measures->findByCategoryOrdered($entity->getCategory());
    }

    #[Route('', name: 'app_centre_sanction_measures_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        $categories         = $this->categories->findByCentreOrdered($centre);
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

    #[Route('/export', name: 'app_centre_sanction_measures_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        return parent::export($centreId);
    }

    #[Route('/import', name: 'app_centre_sanction_measures_import')]
    public function import(string $centreId, Request $request): Response
    {
        return parent::import($centreId, $request);
    }

    #[Route('/nueva', name: 'app_centre_sanction_measures_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'new_sanction_measure_' . $centreId);

        $name         = trim($request->request->getString('name'));
        $categoryId   = $request->request->getString('category_id');
        $hasDateRange = $request->request->getBoolean('has_date_range');
        $category     = $this->categories->findById($categoryId);

        if ($name === '' || $category === null || $category->getEducationalCentre() !== $centre) {
            $this->addFlash('error', $this->t('sanction_measure.flash.invalid'));

            return $this->redirectToRoute('app_centre_sanction_measures_index', ['centreId' => $centreId]);
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

        $this->activityLog->log('sanction_measure.created', [
            'entityId'   => $measure->getId()->toRfc4122(),
            'categoryId' => $category->getId()->toRfc4122(),
            'name'       => $measure->getName(),
        ]);

        $this->addFlash('success', $this->t('sanction_measure.flash.created'));

        return $this->redirectToRoute('app_centre_sanction_measures_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/editar', name: 'app_centre_sanction_measures_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        $measure = $this->measures->findById($id);
        if ($measure === null || $measure->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'edit_sanction_measure_' . $id);

            $name         = trim($request->request->getString('name'));
            $categoryId   = $request->request->getString('category_id');
            $hasDateRange = $request->request->getBoolean('has_date_range');
            $active       = $request->request->getBoolean('active');
            $category     = $this->categories->findById($categoryId);

            if ($name !== '' && $category !== null && $category->getEducationalCentre() === $centre) {
                $before           = $this->changeTracker->snapshot($measure, self::LOGGED_FIELDS);
                $categoryIdBefore = $measure->getCategory()->getId()->toRfc4122();

                $measure->setName($name)
                    ->setCategory($category)
                    ->setHasDateRange($hasDateRange)
                    ->setActive($active);
                $this->em->flush();

                $changes = $this->changeTracker->diff($before, $measure, self::LOGGED_FIELDS);

                $categoryIdAfter = $measure->getCategory()->getId()->toRfc4122();
                if ($categoryIdBefore !== $categoryIdAfter) {
                    $changes['categoryId'] = ['before' => $categoryIdBefore, 'after' => $categoryIdAfter];
                }

                if ($changes !== []) {
                    $this->activityLog->log('sanction_measure.updated', [
                        'entityId' => $measure->getId()->toRfc4122(),
                        'changes'  => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('sanction_measure.flash.updated'));
            }

            return $this->redirectToRoute('app_centre_sanction_measures_index', ['centreId' => $centreId]);
        }

        $categories = $this->categories->findByCentreOrdered($centre);

        return $this->render('admin/sanction_measure/edit.html.twig', [
            'centre'     => $centre,
            'measure'    => $measure,
            'categories' => $categories,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_centre_sanction_measures_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        return parent::delete($centreId, $id, $request);
    }

    #[Route('/{id}/subir', name: 'app_centre_sanction_measures_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        return parent::moveUp($centreId, $id, $request);
    }

    #[Route('/{id}/bajar', name: 'app_centre_sanction_measures_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        return parent::moveDown($centreId, $id, $request);
    }

    #[Route('/{id}/activar', name: 'app_centre_sanction_measures_toggle_active', methods: ['POST'])]
    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        return parent::toggleActive($centreId, $id, $request);
    }
}
