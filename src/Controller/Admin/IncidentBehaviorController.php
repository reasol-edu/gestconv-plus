<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Admin\Catalog\AbstractCatalogController;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Repository\EducationalCentreRepository;
use App\Repository\IncidentBehaviorCategoryRepository;
use App\Repository\IncidentBehaviorRepository;
use App\Service\ActivityLogService;
use App\Service\EntityChangeTracker;
use App\Service\IncidentBehaviorExporter;
use App\Service\IncidentBehaviorImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/conductas')]
class IncidentBehaviorController extends AbstractCatalogController
{
    /** @var list<string> */
    private const LOGGED_FIELDS = ['name', 'active'];

    public function __construct(
        EntityManagerInterface $em,
        EducationalCentreRepository $centres,
        TranslatorInterface $translator,
        ActivityLogService $activityLog,
        private readonly IncidentBehaviorCategoryRepository $categories,
        private readonly IncidentBehaviorRepository $behaviors,
        private readonly IncidentBehaviorExporter $exporterService,
        private readonly IncidentBehaviorImporter $importerService,
        private readonly EntityChangeTracker $changeTracker,
    ) {
        parent::__construct($em, $centres, $translator, $activityLog);
    }

    protected function catalogKey(): string
    {
        return 'behavior';
    }

    protected function logEventPrefix(): string
    {
        return 'incident_behavior';
    }

    protected function indexRoute(): string
    {
        return 'app_centre_incident_behaviors_index';
    }

    protected function exportFilenamePrefix(): string
    {
        return 'conductas';
    }

    protected function exporter(): IncidentBehaviorExporter
    {
        return $this->exporterService;
    }

    protected function importer(): IncidentBehaviorImporter
    {
        return $this->importerService;
    }

    protected function importTemplate(): string
    {
        return 'admin/incident_behavior/import.html.twig';
    }

    protected function importFlashParams(array $stats): array
    {
        return [
            '%categories%' => $stats['categories'],
            '%behaviors%'  => $stats['behaviors'],
        ];
    }

    protected function findEntity(string $id): ?CatalogEntryInterface
    {
        return $this->behaviors->findById($id);
    }

    protected function siblingsOf(CatalogEntryInterface $entity, EducationalCentre $centre): array
    {
        assert($entity instanceof IncidentBehavior);

        return $this->behaviors->findByCategoryOrdered($entity->getCategory());
    }

    #[Route('', name: 'app_centre_incident_behaviors_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        $categories          = $this->categories->findByCentreOrdered($centre);
        $behaviorsByCategory = [];
        foreach ($categories as $cat) {
            $behaviorsByCategory[$cat->getId()->toRfc4122()] = $this->behaviors->findByCategoryOrdered($cat);
        }

        return $this->render('admin/incident_behavior/index.html.twig', [
            'centre'              => $centre,
            'categories'          => $categories,
            'behaviorsByCategory' => $behaviorsByCategory,
        ]);
    }

    #[Route('/export', name: 'app_centre_incident_behaviors_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        return parent::export($centreId);
    }

    #[Route('/import', name: 'app_centre_incident_behaviors_import')]
    public function import(string $centreId, Request $request): Response
    {
        return parent::import($centreId, $request);
    }

    #[Route('/nueva', name: 'app_centre_incident_behaviors_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'new_behavior_' . $centreId);

        $name       = trim($request->request->getString('name'));
        $categoryId = $request->request->getString('category_id');
        $category   = $this->categories->findById($categoryId);

        if ($name === '' || $category === null || $category->getEducationalCentre() !== $centre) {
            $this->addFlash('error', $this->t('behavior.flash.invalid'));

            return $this->redirectToRoute('app_centre_incident_behaviors_index', ['centreId' => $centreId]);
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

        $this->activityLog->log('incident_behavior.created', [
            'entityId'   => $behavior->getId()->toRfc4122(),
            'categoryId' => $category->getId()->toRfc4122(),
            'name'       => $behavior->getName(),
        ]);

        $this->addFlash('success', $this->t('behavior.flash.created'));

        return $this->redirectToRoute('app_centre_incident_behaviors_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/editar', name: 'app_centre_incident_behaviors_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        $behavior = $this->behaviors->findById($id);
        if ($behavior === null || $behavior->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'edit_behavior_' . $id);

            $name       = trim($request->request->getString('name'));
            $categoryId = $request->request->getString('category_id');
            $active     = $request->request->getBoolean('active');
            $category   = $this->categories->findById($categoryId);

            if ($name !== '' && $category !== null && $category->getEducationalCentre() === $centre) {
                $before           = $this->changeTracker->snapshot($behavior, self::LOGGED_FIELDS);
                $categoryIdBefore = $behavior->getCategory()->getId()->toRfc4122();

                $behavior->setName($name)->setCategory($category)->setActive($active);
                $this->em->flush();

                $changes = $this->changeTracker->diff($before, $behavior, self::LOGGED_FIELDS);

                $categoryIdAfter = $behavior->getCategory()->getId()->toRfc4122();
                if ($categoryIdBefore !== $categoryIdAfter) {
                    $changes['categoryId'] = ['before' => $categoryIdBefore, 'after' => $categoryIdAfter];
                }

                if ($changes !== []) {
                    $this->activityLog->log('incident_behavior.updated', [
                        'entityId' => $behavior->getId()->toRfc4122(),
                        'changes'  => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('behavior.flash.updated'));
            }

            return $this->redirectToRoute('app_centre_incident_behaviors_index', ['centreId' => $centreId]);
        }

        $categories = $this->categories->findByCentreOrdered($centre);

        return $this->render('admin/incident_behavior/edit.html.twig', [
            'centre'     => $centre,
            'behavior'   => $behavior,
            'categories' => $categories,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_centre_incident_behaviors_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        return parent::delete($centreId, $id, $request);
    }

    #[Route('/{id}/subir', name: 'app_centre_incident_behaviors_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        return parent::moveUp($centreId, $id, $request);
    }

    #[Route('/{id}/bajar', name: 'app_centre_incident_behaviors_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        return parent::moveDown($centreId, $id, $request);
    }

    #[Route('/{id}/activar', name: 'app_centre_incident_behaviors_toggle_active', methods: ['POST'])]
    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        return parent::toggleActive($centreId, $id, $request);
    }
}
