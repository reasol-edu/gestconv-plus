<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\Admin\Catalog\AbstractCatalogController;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Repository\EducationalCentreRepository;
use App\Repository\ExitReasonRepository;
use App\Repository\StudentExitRepository;
use App\Service\ActivityLogService;
use App\Service\EntityChangeTracker;
use App\Service\ExitReasonExporter;
use App\Service\ExitReasonImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/motivos-salida')]
class ExitReasonController extends AbstractCatalogController
{
    /** @var list<string> */
    private const LOGGED_FIELDS = ['name', 'active'];

    public function __construct(
        EntityManagerInterface $em,
        EducationalCentreRepository $centres,
        TranslatorInterface $translator,
        ActivityLogService $activityLog,
        private readonly ExitReasonRepository $reasons,
        private readonly StudentExitRepository $exits,
        private readonly ExitReasonExporter $exporterService,
        private readonly ExitReasonImporter $importerService,
        private readonly EntityChangeTracker $changeTracker,
    ) {
        parent::__construct($em, $centres, $translator, $activityLog);
    }

    protected function catalogKey(): string
    {
        return 'exit_reason';
    }

    protected function logEventPrefix(): string
    {
        return 'exit_reason';
    }

    protected function indexRoute(): string
    {
        return 'app_centre_exit_reasons_index';
    }

    protected function exportFilenamePrefix(): string
    {
        return 'motivos-salida';
    }

    protected function exporter(): ExitReasonExporter
    {
        return $this->exporterService;
    }

    protected function importer(): ExitReasonImporter
    {
        return $this->importerService;
    }

    protected function importTemplate(): string
    {
        return 'admin/exit_reason/import.html.twig';
    }

    protected function importFlashParams(array $stats): array
    {
        return [
            '%reasons%' => $stats['reasons'],
        ];
    }

    protected function findEntity(string $id): ?CatalogEntryInterface
    {
        return $this->reasons->findById($id);
    }

    protected function siblingsOf(CatalogEntryInterface $entity, EducationalCentre $centre): array
    {
        return $this->reasons->findByCentreOrdered($centre);
    }

    protected function deletionBlocked(CatalogEntryInterface $entity): bool
    {
        assert($entity instanceof ExitReason);

        return $this->exits->countByReason($entity) > 0;
    }

    #[Route('', name: 'app_centre_exit_reasons_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/exit_reason/index.html.twig', [
            'centre'  => $centre,
            'reasons' => $this->reasons->findByCentreOrdered($centre),
        ]);
    }

    #[Route('/export', name: 'app_centre_exit_reasons_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        return parent::export($centreId);
    }

    #[Route('/import', name: 'app_centre_exit_reasons_import')]
    public function import(string $centreId, Request $request): Response
    {
        return parent::import($centreId, $request);
    }

    #[Route('/nuevo', name: 'app_centre_exit_reasons_create', methods: ['POST'])]
    public function create(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'new_exit_reason_' . $centreId);

        $name = trim($request->request->getString('name'));

        if ($name === '') {
            $this->addFlash('error', $this->t('exit_reason.flash.invalid'));

            return $this->redirectToRoute('app_centre_exit_reasons_index', ['centreId' => $centreId]);
        }

        $reason = (new ExitReason())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($this->reasons->countByCentre($centre))
            ->setActive(true);

        $this->em->persist($reason);
        $this->em->flush();

        $this->activityLog->log('exit_reason.created', [
            'entityId' => $reason->getId()->toRfc4122(),
            'name'     => $reason->getName(),
        ]);

        $this->addFlash('success', $this->t('exit_reason.flash.created'));

        return $this->redirectToRoute('app_centre_exit_reasons_index', ['centreId' => $centreId]);
    }

    #[Route('/{id}/editar', name: 'app_centre_exit_reasons_edit', methods: ['GET', 'POST'])]
    public function edit(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        $reason = $this->reasons->findById($id);
        if ($reason === null || $reason->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'edit_exit_reason_' . $id);

            $name   = trim($request->request->getString('name'));
            $active = $request->request->getBoolean('active');

            if ($name !== '') {
                $before = $this->changeTracker->snapshot($reason, self::LOGGED_FIELDS);

                $reason->setName($name)->setActive($active);
                $this->em->flush();

                $changes = $this->changeTracker->diff($before, $reason, self::LOGGED_FIELDS);
                if ($changes !== []) {
                    $this->activityLog->log('exit_reason.updated', [
                        'entityId' => $reason->getId()->toRfc4122(),
                        'changes'  => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('exit_reason.flash.updated'));
            } else {
                $this->addFlash('error', $this->t('exit_reason.flash.invalid'));
            }

            return $this->redirectToRoute('app_centre_exit_reasons_index', ['centreId' => $centreId]);
        }

        return $this->render('admin/exit_reason/edit.html.twig', [
            'centre' => $centre,
            'reason' => $reason,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_centre_exit_reasons_delete', methods: ['POST'])]
    public function delete(string $centreId, string $id, Request $request): Response
    {
        return parent::delete($centreId, $id, $request);
    }

    #[Route('/{id}/subir', name: 'app_centre_exit_reasons_move_up', methods: ['POST'])]
    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        return parent::moveUp($centreId, $id, $request);
    }

    #[Route('/{id}/bajar', name: 'app_centre_exit_reasons_move_down', methods: ['POST'])]
    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        return parent::moveDown($centreId, $id, $request);
    }

    #[Route('/{id}/activar', name: 'app_centre_exit_reasons_toggle_active', methods: ['POST'])]
    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        return parent::toggleActive($centreId, $id, $request);
    }
}
