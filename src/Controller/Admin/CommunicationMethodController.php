<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Admin\Catalog\AbstractCatalogController;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\CommunicationMethod;
use App\Entity\EducationalCentre;
use App\Repository\CommunicationMethodRepository;
use App\Repository\CommunicationRepository;
use App\Repository\EducationalCentreRepository;
use App\Service\ActivityLogService;
use App\Service\CommunicationMethodExporter;
use App\Service\CommunicationMethodImporter;
use App\Service\EntityChangeTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/metodos-comunicacion')]
class CommunicationMethodController extends AbstractCatalogController
{
    /** @var list<string> */
    private const LOGGED_FIELDS = ['name', 'active'];

    public function __construct(
        EntityManagerInterface $em,
        EducationalCentreRepository $centres,
        TranslatorInterface $translator,
        ActivityLogService $activityLog,
        private readonly CommunicationMethodRepository $methods,
        private readonly CommunicationRepository $communications,
        private readonly CommunicationMethodExporter $exporterService,
        private readonly CommunicationMethodImporter $importerService,
        private readonly EntityChangeTracker $changeTracker,
    ) {
        parent::__construct($em, $centres, $translator, $activityLog);
    }

    protected function catalogKey(): string
    {
        return 'communication_method';
    }

    protected function logEventPrefix(): string
    {
        return 'communication_method';
    }

    protected function indexRoute(): string
    {
        return 'app_centre_communication_methods_index';
    }

    protected function exportFilenamePrefix(): string
    {
        return 'metodos-comunicacion';
    }

    protected function exporter(): CommunicationMethodExporter
    {
        return $this->exporterService;
    }

    protected function importer(): CommunicationMethodImporter
    {
        return $this->importerService;
    }

    protected function importTemplate(): string
    {
        return 'admin/communication_method/import.html.twig';
    }

    protected function importFlashParams(array $stats): array
    {
        return [
            '%methods%' => $stats['methods'],
        ];
    }

    protected function findEntity(string $id): ?CatalogEntryInterface
    {
        return $this->methods->findById($id);
    }

    protected function siblingsOf(CatalogEntryInterface $entity, EducationalCentre $centre): array
    {
        return $this->methods->findByCentreOrdered($centre);
    }

    protected function deletionBlocked(CatalogEntryInterface $entity): bool
    {
        assert($entity instanceof CommunicationMethod);

        return $this->communications->countByMethod($entity) > 0;
    }

    #[Route('', name: 'app_centre_communication_methods_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/communication_method/index.html.twig', [
            'centre'  => $centre,
            'methods' => $this->methods->findByCentreOrdered($centre),
        ]);
    }

    #[Route('/export', name: 'app_centre_communication_methods_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        return parent::export($centreId);
    }

    #[Route('/import', name: 'app_centre_communication_methods_import')]
    public function import(string $centreId, Request $request): Response
    {
        return parent::import($centreId, $request);
    }

    #[Route('/nuevo', name: 'app_centre_communication_methods_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'new_communication_method_' . $centreId);

        $name = trim($request->request->getString('name'));

        if ($name === '') {
            $this->addFlash('error', $this->t('communication_method.flash.invalid'));

            return $this->redirectToRoute('app_centre_communication_methods_index', ['centreId' => $centreId]);
        }

        $method = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($this->methods->countByCentre($centre))
            ->setActive(true);

        $this->em->persist($method);
        $this->em->flush();

        $this->activityLog->log('communication_method.created', [
            'entityId' => $method->getId()->toRfc4122(),
            'name'     => $method->getName(),
        ]);

        $this->addFlash('success', $this->t('communication_method.flash.created'));

        return $this->redirectToRoute('app_centre_communication_methods_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/editar', name: 'app_centre_communication_methods_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        $method = $this->methods->findById($id);
        if ($method === null || $method->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'edit_communication_method_' . $id);

            $name   = trim($request->request->getString('name'));
            $active = $request->request->getBoolean('active');

            if ($name !== '') {
                $before = $this->changeTracker->snapshot($method, self::LOGGED_FIELDS);

                $method->setName($name)->setActive($active);
                $this->em->flush();

                $changes = $this->changeTracker->diff($before, $method, self::LOGGED_FIELDS);
                if ($changes !== []) {
                    $this->activityLog->log('communication_method.updated', [
                        'entityId' => $method->getId()->toRfc4122(),
                        'changes'  => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('communication_method.flash.updated'));
            } else {
                $this->addFlash('error', $this->t('communication_method.flash.invalid'));
            }

            return $this->redirectToRoute('app_centre_communication_methods_index', ['centreId' => $centreId]);
        }

        return $this->render('admin/communication_method/edit.html.twig', [
            'centre' => $centre,
            'method' => $method,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_centre_communication_methods_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        return parent::delete($centreId, $id, $request);
    }

    #[Route('/{id}/subir', name: 'app_centre_communication_methods_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        return parent::moveUp($centreId, $id, $request);
    }

    #[Route('/{id}/bajar', name: 'app_centre_communication_methods_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        return parent::moveDown($centreId, $id, $request);
    }

    #[Route('/{id}/activar', name: 'app_centre_communication_methods_toggle_active', methods: ['POST'])]
    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        return parent::toggleActive($centreId, $id, $request);
    }
}
