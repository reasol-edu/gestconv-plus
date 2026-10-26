<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Admin\Catalog\AbstractCatalogController;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\SchoolEventType;
use App\Repository\EducationalCentreRepository;
use App\Repository\SchoolEventRepository;
use App\Repository\SchoolEventTypeRepository;
use App\Service\ActivityLogService;
use App\Service\EntityChangeTracker;
use App\Service\SchoolEventTypeColor;
use App\Service\SchoolEventTypeExporter;
use App\Service\SchoolEventTypeImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/tipos-actividad')]
class SchoolEventTypeController extends AbstractCatalogController
{
    /** @var list<string> */
    private const LOGGED_FIELDS = ['name', 'color', 'active'];

    public function __construct(
        EntityManagerInterface $em,
        EducationalCentreRepository $centres,
        TranslatorInterface $translator,
        ActivityLogService $activityLog,
        private readonly SchoolEventTypeRepository $types,
        private readonly SchoolEventRepository $events,
        private readonly SchoolEventTypeExporter $exporterService,
        private readonly SchoolEventTypeImporter $importerService,
        private readonly EntityChangeTracker $changeTracker,
    ) {
        parent::__construct($em, $centres, $translator, $activityLog);
    }

    protected function catalogKey(): string
    {
        return 'school_event_type';
    }

    protected function logEventPrefix(): string
    {
        return 'school_event_type';
    }

    protected function indexRoute(): string
    {
        return 'app_centre_school_event_types_index';
    }

    protected function exportFilenamePrefix(): string
    {
        return 'tipos-actividad';
    }

    protected function exporter(): SchoolEventTypeExporter
    {
        return $this->exporterService;
    }

    protected function importer(): SchoolEventTypeImporter
    {
        return $this->importerService;
    }

    protected function importTemplate(): string
    {
        return 'admin/school_event_type/import.html.twig';
    }

    protected function importFlashParams(array $stats): array
    {
        return [
            '%types%' => $stats['types'],
        ];
    }

    protected function findEntity(string $id): ?CatalogEntryInterface
    {
        return $this->types->findById($id);
    }

    protected function siblingsOf(CatalogEntryInterface $entity, EducationalCentre $centre): array
    {
        return $this->types->findByCentreOrdered($centre);
    }

    protected function deletionBlocked(CatalogEntryInterface $entity): bool
    {
        assert($entity instanceof SchoolEventType);

        return $this->events->countByType($entity) > 0;
    }

    #[Route('', name: 'app_centre_school_event_types_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/school_event_type/index.html.twig', [
            'centre' => $centre,
            'types'  => $this->types->findByCentreOrdered($centre),
        ]);
    }

    #[Route('/export', name: 'app_centre_school_event_types_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        return parent::export($centreId);
    }

    #[Route('/import', name: 'app_centre_school_event_types_import')]
    public function import(string $centreId, Request $request): Response
    {
        return parent::import($centreId, $request);
    }

    #[Route('/nuevo', name: 'app_centre_school_event_types_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'new_school_event_type_' . $centreId);

        $name = trim($request->request->getString('name'));

        if ($name === '') {
            $this->addFlash('error', $this->t('school_event_type.flash.invalid'));

            return $this->redirectToRoute('app_centre_school_event_types_index', ['centreId' => $centreId]);
        }

        $type = (new SchoolEventType())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setColor(SchoolEventTypeColor::normalize($request->request->getString('color')))
            ->setPosition($this->types->countByCentre($centre))
            ->setActive(true);

        $this->em->persist($type);
        $this->em->flush();

        $this->activityLog->log('school_event_type.created', [
            'entityId' => $type->getId()->toRfc4122(),
            'name'     => $type->getName(),
        ]);

        $this->addFlash('success', $this->t('school_event_type.flash.created'));

        return $this->redirectToRoute('app_centre_school_event_types_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/editar', name: 'app_centre_school_event_types_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        $type = $this->types->findById($id);
        if ($type === null || $type->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'edit_school_event_type_' . $id);

            $name   = trim($request->request->getString('name'));
            $active = $request->request->getBoolean('active');
            // Sin color (o con uno que no es #rrggbb) el tipo se muestra en gris.
            $color  = $request->request->getBoolean('no_color') ? null : SchoolEventTypeColor::normalize($request->request->getString('color'));

            if ($name !== '') {
                $before = $this->changeTracker->snapshot($type, self::LOGGED_FIELDS);

                $type->setName($name)->setColor($color)->setActive($active);
                $this->em->flush();

                $changes = $this->changeTracker->diff($before, $type, self::LOGGED_FIELDS);
                if ($changes !== []) {
                    $this->activityLog->log('school_event_type.updated', [
                        'entityId' => $type->getId()->toRfc4122(),
                        'changes'  => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('school_event_type.flash.updated'));
            } else {
                $this->addFlash('error', $this->t('school_event_type.flash.invalid'));
            }

            return $this->redirectToRoute('app_centre_school_event_types_index', ['centreId' => $centreId]);
        }

        return $this->render('admin/school_event_type/edit.html.twig', [
            'centre' => $centre,
            'type'   => $type,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_centre_school_event_types_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        return parent::delete($centreId, $id, $request);
    }

    #[Route('/{id}/subir', name: 'app_centre_school_event_types_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        return parent::moveUp($centreId, $id, $request);
    }

    #[Route('/{id}/bajar', name: 'app_centre_school_event_types_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        return parent::moveDown($centreId, $id, $request);
    }

    #[Route('/{id}/activar', name: 'app_centre_school_event_types_toggle_active', methods: ['POST'])]
    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        return parent::toggleActive($centreId, $id, $request);
    }
}
