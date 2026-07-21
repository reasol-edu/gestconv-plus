<?php

declare(strict_types=1);

namespace App\Controller\Admin\Catalog;

use App\Controller\TranslatorTrait;
use App\Entity\Catalog\CatalogEntryInterface;
use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use App\Service\Catalog\AbstractCatalogExporter;
use App\Service\Catalog\AbstractCatalogImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Acciones comunes a los 4 catálogos administrables (IncidentBehavior, LocationOption,
 * SanctionMeasure, CommunicationMethod): exportar, importar, eliminar, reordenar y activar/
 * desactivar una entrada. Cada controlador concreto añade el atributo #[Route] a un método
 * envoltorio que llama a `parent::metodo(...)`, ya que el nombre de ruta varía por catálogo.
 *
 * `index()`, `create()` y `edit()` se quedan en cada controlador: difieren en variables de
 * plantilla y en campos propios del catálogo (categoría, hasDateRange...).
 */
abstract class AbstractCatalogController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly EducationalCentreRepository $centres,
        private readonly TranslatorInterface $translator,
        protected readonly ActivityLogService $activityLog,
    ) {}

    /** Clave de traducción/CSRF: 'behavior', 'location', 'sanction_measure', 'communication_method'. */
    abstract protected function catalogKey(): string;

    /** Prefijo de los eventos del registro de actividad, p. ej. 'incident_behavior'. */
    abstract protected function logEventPrefix(): string;

    abstract protected function indexRoute(): string;

    abstract protected function exportFilenamePrefix(): string;

    abstract protected function exporter(): AbstractCatalogExporter;

    abstract protected function importer(): AbstractCatalogImporter;

    abstract protected function importTemplate(): string;

    /**
     * @param array<string, int> $stats
     * @return array<string, mixed>
     */
    abstract protected function importFlashParams(array $stats): array;

    abstract protected function findEntity(string $id): ?CatalogEntryInterface;

    /**
     * Hermanas ordenadas de la entrada (misma categoría, o mismo centro si el catálogo es
     * plano). Se reutiliza tanto para mover como para renumerar tras un borrado.
     *
     * @return list<CatalogEntryInterface>
     */
    abstract protected function siblingsOf(CatalogEntryInterface $entity, EducationalCentre $centre): array;

    /** Si la entrada no puede borrarse porque está en uso (solo CommunicationMethod). */
    protected function deletionBlocked(CatalogEntryInterface $entity): bool
    {
        return false;
    }

    protected function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }

    protected function checkCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    protected function requireEntity(string $id, EducationalCentre $centre): CatalogEntryInterface
    {
        $entity = $this->findEntity($id);
        if ($entity === null || $entity->getEducationalCentre() !== $centre) {
            throw $this->createNotFoundException();
        }

        return $entity;
    }

    public function export(string $centreId): JsonResponse
    {
        $centre = $this->requireCentre($centreId);

        $data     = $this->exporter()->export($centre);
        $filename = $this->exportFilenamePrefix() . '-' . $centre->getCode() . '.json';
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], true);
    }

    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        if (!$request->isMethod('POST')) {
            return $this->render($this->importTemplate(), ['centre' => $centre]);
        }

        $this->checkCsrf($request, 'import_' . $this->catalogKey() . 's');

        $file = $request->files->get('json');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t($this->catalogKey() . '.import.error.no_file'));

            return $this->render($this->importTemplate(), ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $this->addFlash('error', $this->t($this->catalogKey() . '.import.error.invalid_json'));

            return $this->render($this->importTemplate(), ['centre' => $centre]);
        }

        /** @var array<string, mixed> $decoded */
        $stats = $this->importer()->import($decoded, $centre, $request->request->has('replace_existing'));

        $this->addFlash('success', $this->translator->trans(
            $this->catalogKey() . '.import.flash.summary',
            $this->importFlashParams($stats),
            'admin',
        ));

        return $this->redirectToRoute($this->indexRoute(), ['centreId' => $centreId]);
    }

    public function delete(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'delete_' . $this->catalogKey() . '_' . $id);
        $entity = $this->requireEntity($id, $centre);

        if ($this->deletionBlocked($entity)) {
            $this->addFlash('error', $this->t($this->catalogKey() . '.flash.in_use'));

            return $this->redirectToRoute($this->indexRoute(), ['centreId' => $centreId]);
        }

        $entityId = $entity->getId()->toRfc4122();
        $name     = $entity->getName();

        $this->em->remove($entity);
        $this->em->flush();

        foreach ($this->siblingsOf($entity, $centre) as $pos => $sibling) {
            $sibling->setPosition($pos);
        }
        $this->em->flush();

        $this->activityLog->log($this->logEventPrefix() . '.deleted', [
            'entityId' => $entityId,
            'name'     => $name,
        ]);

        $this->addFlash('success', $this->t($this->catalogKey() . '.flash.deleted'));

        return $this->redirectToRoute($this->indexRoute(), ['centreId' => $centreId]);
    }

    public function moveUp(string $centreId, string $id, Request $request): Response
    {
        return $this->move($centreId, $id, $request, up: true);
    }

    public function moveDown(string $centreId, string $id, Request $request): Response
    {
        return $this->move($centreId, $id, $request, up: false);
    }

    private function move(string $centreId, string $id, Request $request, bool $up): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'move_' . $this->catalogKey() . '_' . $id);
        $entity = $this->requireEntity($id, $centre);

        $siblings = $this->siblingsOf($entity, $centre);
        $count    = count($siblings);
        foreach ($siblings as $i => $sibling) {
            if ($sibling->getId()->toRfc4122() !== $id) {
                continue;
            }

            $other = match (true) {
                $up && $i > 0 => $siblings[$i - 1],
                !$up && $i < $count - 1 => $siblings[$i + 1],
                default => null,
            };

            if ($other !== null) {
                $position = $sibling->getPosition();
                $sibling->setPosition($other->getPosition());
                $other->setPosition($position);
                $this->em->flush();
            }

            break;
        }

        $this->addFlash('success', $this->t($this->catalogKey() . '.flash.moved'));

        return $this->redirectToRoute($this->indexRoute(), ['centreId' => $centreId]);
    }

    public function toggleActive(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'toggle_' . $this->catalogKey() . '_' . $id);
        $entity = $this->requireEntity($id, $centre);

        $entity->setActive(!$entity->isActive());
        $this->em->flush();

        $this->addFlash('success', $this->t($this->catalogKey() . '.flash.toggled'));

        return $this->redirectToRoute($this->indexRoute(), ['centreId' => $centreId]);
    }
}
