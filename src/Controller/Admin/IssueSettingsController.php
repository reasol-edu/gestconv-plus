<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Entity\IssuePriority;
use App\Entity\IssueType;
use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Repository\IssuePriorityRepository;
use App\Repository\IssueRepository;
use App\Repository\IssueTypeRepository;
use App\Service\IssueAssignees;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use App\Service\IssueSettingsExporter;
use App\Service\IssueSettingsImporter;
use App\Service\IssueSettingsImportOptions;
use App\Service\UploadLimits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Configuración de las incidencias de un centro: la lista jerárquica de tipos (con los responsables de cada nodo) y las
 * prioridades (ordenadas, con color y plazos), y su exportación e importación en JSON con vista previa.
 *
 * @phpstan-import-type IssueSettingsFile from IssueSettingsImporter
 */
#[Route('/centro/{centreId}/incidencias')]
class IssueSettingsController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
        private readonly IssueTypeRepository $types,
        private readonly IssuePriorityRepository $priorities,
        private readonly IssueRepository $issues,
        private readonly IssueAssignees $assignees,
        private readonly IssueSettingsExporter $exporter,
        private readonly IssueSettingsImporter $importer,
        private readonly UploadLimits $uploadLimits,
    ) {}

    #[Route('', name: 'app_centre_issue_settings', methods: ['GET'])]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        $rows = [];
        foreach ($this->flatten($this->types->findByCentre($centre)) as [$type, $depth]) {
            $rows[] = ['type' => $type, 'depth' => $depth, 'inherited' => $this->inheritedManagers($type)];
        }

        return $this->render('admin/issue_settings/index.html.twig', [
            'centre'     => $centre,
            'rows'       => $rows,
            'priorities' => $this->priorities->findByCentreOrdered($centre),
        ]);
    }

    // ── Tipos ─────────────────────────────────────────────────────────────────

    #[Route('/tipos/nuevo', name: 'app_centre_issue_type_create', methods: ['POST'])]
    public function createType(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'create_issue_type');

        $name = $this->cleanName($request->request->getString('name'), 255);
        if ($name === null) {
            $this->addFlash('error', $this->t('issue_settings.flash.invalid'));

            return $this->redirectToRoute('app_centre_issue_settings', ['centreId' => $centreId]);
        }

        $parentId = $request->request->getString('parent');
        $parent   = $parentId !== '' ? $this->types->findByCentreAndId($centre, $parentId) : null;

        $type = (new IssueType())->setEducationalCentre($centre)->setName($name)->setParent($parent);
        $this->em->persist($type);
        $this->em->flush();

        $this->activityLog->log('issue_type.created', ['entityId' => $type->getId()->toRfc4122(), 'name' => $type->getPath()]);
        $this->addFlash('success', $this->t('issue_settings.flash.type_created'));

        return $this->redirectToRoute('app_centre_issue_type_edit', ['centreId' => $centreId, 'id' => $type->getId()->toRfc4122()]);
    }

    #[Route('/tipos/{id}/editar', name: 'app_centre_issue_type_edit', methods: ['GET', 'POST'])]
    public function editType(string $centreId, string $id, Request $request): Response
    {
        $centre   = $this->requireCentre($centreId);
        $type     = $this->requireType($id, $centre);
        $teachers = $this->selectableTeachers($centre, $type);

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'edit_issue_type_' . $id);

            $name = $this->cleanName($request->request->getString('name'), 255);
            if ($name === null) {
                $this->addFlash('error', $this->t('issue_settings.flash.invalid'));

                return $this->redirectToRoute('app_centre_issue_type_edit', ['centreId' => $centreId, 'id' => $id]);
            }

            $parentId = $request->request->getString('parent');
            $parent   = $parentId !== '' ? $this->types->findByCentreAndId($centre, $parentId) : null;
            if ($type->wouldCycleUnder($parent)) {
                $this->addFlash('error', $this->t('issue_settings.flash.type_cycle'));

                return $this->redirectToRoute('app_centre_issue_type_edit', ['centreId' => $centreId, 'id' => $id]);
            }

            $type->setName($name)->setParent($parent)->setActive($request->request->getBoolean('active'));

            // Los responsables han de ser docentes del curso (o quienes ya lo eran de este tipo); lo demás se ignora.
            $selected = [];
            foreach ($request->request->all('managers') as $teacherId) {
                if (is_string($teacherId) && isset($teachers[$teacherId])) {
                    $selected[$teacherId] = $teachers[$teacherId];
                }
            }
            foreach ($type->getManagers()->toArray() as $manager) {
                if (!isset($selected[$manager->getId()->toRfc4122()])) {
                    $type->removeManager($manager);
                }
            }
            foreach ($selected as $manager) {
                $type->addManager($manager);
            }

            $this->em->flush();

            $this->activityLog->log('issue_type.updated', [
                'entityId' => $id,
                'name'     => $type->getPath(),
                'managers' => array_values(array_map(static fn (Teacher $t): string => $t->getName()->full(), $selected)),
            ]);
            $this->addFlash('success', $this->t('issue_settings.flash.type_updated'));

            return $this->redirectToRoute('app_centre_issue_settings', ['centreId' => $centreId]);
        }

        $options = [];
        foreach ($this->flatten($this->types->findByCentre($centre)) as [$candidate, $depth]) {
            if (!$type->wouldCycleUnder($candidate)) {
                $options[] = ['type' => $candidate, 'depth' => $depth];
            }
        }

        return $this->render('admin/issue_settings/type_edit.html.twig', [
            'centre'             => $centre,
            'type'               => $type,
            'parentOptions'      => $options,
            'availableTeachers'  => array_values($teachers),
            'selectedManagerIds' => array_map(static fn (Teacher $t): string => $t->getId()->toRfc4122(), $type->getManagers()->toArray()),
            'inherited'          => $this->inheritedManagers($type),
        ]);
    }

    #[Route('/tipos/{id}/eliminar', name: 'app_centre_issue_type_delete', methods: ['POST'])]
    public function deleteType(string $centreId, string $id, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $type   = $this->requireType($id, $centre);
        $this->checkCsrf($request, 'delete_issue_type_' . $id);

        if ($this->types->countChildren($type) > 0) {
            $this->addFlash('error', $this->t('issue_settings.flash.type_has_children'));
        } elseif ($this->issues->countByType($type) > 0) {
            $this->addFlash('error', $this->t('issue_settings.flash.type_in_use'));
        } else {
            $this->activityLog->log('issue_type.deleted', ['entityId' => $id, 'name' => $type->getPath()]);
            $this->em->remove($type);
            $this->em->flush();
            $this->addFlash('success', $this->t('issue_settings.flash.type_deleted'));
        }

        return $this->redirectToRoute('app_centre_issue_settings', ['centreId' => $centreId]);
    }

    // ── Prioridades ───────────────────────────────────────────────────────────

    #[Route('/prioridades/nueva', name: 'app_centre_issue_priority_create', methods: ['POST'])]
    public function createPriority(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'create_issue_priority');

        $name = $this->cleanName($request->request->getString('name'), 100);
        if ($name === null) {
            $this->addFlash('error', $this->t('issue_settings.flash.invalid'));

            return $this->redirectToRoute('app_centre_issue_settings', ['centreId' => $centreId]);
        }

        $priority = (new IssuePriority())->setEducationalCentre($centre)->setName($name)->setPosition($this->priorities->nextPosition($centre));
        $this->em->persist($priority);
        $this->em->flush();

        $this->activityLog->log('issue_priority.created', ['entityId' => $priority->getId()->toRfc4122(), 'name' => $name]);
        $this->addFlash('success', $this->t('issue_settings.flash.priority_created'));

        return $this->redirectToRoute('app_centre_issue_priority_edit', ['centreId' => $centreId, 'id' => $priority->getId()->toRfc4122()]);
    }

    #[Route('/prioridades/{id}/editar', name: 'app_centre_issue_priority_edit', methods: ['GET', 'POST'])]
    public function editPriority(string $centreId, string $id, Request $request): Response
    {
        $centre   = $this->requireCentre($centreId);
        $priority = $this->requirePriority($id, $centre);

        if ($request->isMethod('POST')) {
            $this->checkCsrf($request, 'edit_issue_priority_' . $id);

            $name = $this->cleanName($request->request->getString('name'), 100);
            if ($name === null) {
                $this->addFlash('error', $this->t('issue_settings.flash.invalid'));

                return $this->redirectToRoute('app_centre_issue_priority_edit', ['centreId' => $centreId, 'id' => $id]);
            }

            $priority
                ->setName($name)
                ->setColor($request->request->getString('color'))
                ->setRecommendedDays($this->days($request->request->getString('recommended_days')))
                ->setMaxDays($this->days($request->request->getString('max_days')));
            $this->em->flush();

            $this->activityLog->log('issue_priority.updated', ['entityId' => $id, 'name' => $name]);
            $this->addFlash('success', $this->t('issue_settings.flash.priority_updated'));

            return $this->redirectToRoute('app_centre_issue_settings', ['centreId' => $centreId]);
        }

        return $this->render('admin/issue_settings/priority_edit.html.twig', ['centre' => $centre, 'priority' => $priority]);
    }

    /** Sube o baja una prioridad en la lista (la primera es la más importante). */
    #[Route('/prioridades/{id}/mover/{direction}', name: 'app_centre_issue_priority_move', requirements: ['direction' => 'subir|bajar'], methods: ['POST'])]
    public function movePriority(string $centreId, string $id, string $direction, Request $request): Response
    {
        $centre   = $this->requireCentre($centreId);
        $priority = $this->requirePriority($id, $centre);
        $this->checkCsrf($request, 'move_issue_priority_' . $id);

        $list  = $this->priorities->findByCentreOrdered($centre);
        $index = 0;
        foreach ($list as $i => $candidate) {
            if ($candidate->getId()->equals($priority->getId())) {
                $index = $i;
            }
        }
        $swap = $direction === 'subir' ? $index - 1 : $index + 1;
        if (isset($list[$swap])) {
            [$list[$index], $list[$swap]] = [$list[$swap], $list[$index]];
            // Se renumera toda la lista para que no queden posiciones repetidas.
            foreach ($list as $position => $item) {
                $item->setPosition($position);
            }
            $this->em->flush();
            $this->activityLog->log('issue_priority.moved', ['entityId' => $id, 'name' => $priority->getName()]);
        }

        return $this->redirectToRoute('app_centre_issue_settings', ['centreId' => $centreId]);
    }

    #[Route('/prioridades/{id}/eliminar', name: 'app_centre_issue_priority_delete', methods: ['POST'])]
    public function deletePriority(string $centreId, string $id, Request $request): Response
    {
        $centre   = $this->requireCentre($centreId);
        $priority = $this->requirePriority($id, $centre);
        $this->checkCsrf($request, 'delete_issue_priority_' . $id);

        if ($this->issues->countByPriority($priority) > 0) {
            $this->addFlash('error', $this->t('issue_settings.flash.priority_in_use'));
        } else {
            $this->activityLog->log('issue_priority.deleted', ['entityId' => $id, 'name' => $priority->getName()]);
            $this->em->remove($priority);
            $this->em->flush();
            $this->addFlash('success', $this->t('issue_settings.flash.priority_deleted'));
        }

        return $this->redirectToRoute('app_centre_issue_settings', ['centreId' => $centreId]);
    }

    // ── Exportar e importar ───────────────────────────────────────────────────

    #[Route('/exportar', name: 'app_centre_issue_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        $centre = $this->requireCentre($centreId);

        $json = json_encode($this->exporter->export($centre), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="incidencias-' . $centre->getCode() . '.json"',
        ], true);
    }

    /** Paso 1: elegir el fichero. Paso 2 (POST): vista previa y selección. */
    #[Route('/importar', name: 'app_centre_issue_import', methods: ['GET', 'POST'])]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/issue_settings/import.html.twig', ['centre' => $centre]);
        }

        $this->checkCsrf($request, 'import_issue_settings');

        $file    = $request->files->get('json');
        $problem = $this->uploadLimits->fileProblem($file);
        if ($problem !== null) {
            $this->addFlash('error', $problem);

            return $this->render('admin/issue_settings/import.html.twig', ['centre' => $centre]);
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('issue_settings.import.error.no_file'));

            return $this->render('admin/issue_settings/import.html.twig', ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());
        $parsed  = $this->importer->parse(json_decode($content, true));
        if ($parsed === null) {
            $this->addFlash('error', $this->t('issue_settings.import.error.invalid'));

            return $this->render('admin/issue_settings/import.html.twig', ['centre' => $centre]);
        }
        if ($parsed['types'] === [] && $parsed['priorities'] === []) {
            $this->addFlash('error', $this->t('issue_settings.import.error.empty'));

            return $this->render('admin/issue_settings/import.html.twig', ['centre' => $centre]);
        }

        // El fichero se guarda hasta la confirmación, igual que en la importación de la oferta formativa.
        $importId = Uuid::v4()->toRfc4122();
        $path     = $this->tempPath($importId);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
        $request->getSession()->set('issue_settings_import_id', $importId);

        return $this->renderPreview($centre, $importId, $parsed, null);
    }

    /** Paso 3: aplicar la selección y las opciones elegidas en la vista previa. */
    #[Route('/importar/confirmar', name: 'app_centre_issue_import_confirm', methods: ['POST'])]
    public function confirm(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $this->checkCsrf($request, 'import_issue_settings_confirm');

        $importId = $request->request->getString('import_id');
        $parsed   = null;
        if (Uuid::isValid($importId) && $importId === $request->getSession()->get('issue_settings_import_id') && is_file($this->tempPath($importId))) {
            $parsed = $this->importer->parse(json_decode((string) file_get_contents($this->tempPath($importId)), true));
        }
        if ($parsed === null) {
            $this->addFlash('error', $this->t('issue_settings.import.error.expired'));

            return $this->redirectToRoute('app_centre_issue_import', ['centreId' => $centreId]);
        }

        // Los índices que llegan del formulario se validan contra el fichero: solo cuentan los que existen.
        $typeIndexes     = $this->indexes($request->request->all('types'), count($parsed['types']));
        $priorityIndexes = $this->indexes($request->request->all('priorities'), count($parsed['priorities']));
        if ($typeIndexes === [] && $priorityIndexes === []) {
            return $this->renderPreview($centre, $importId, $parsed, $this->t('issue_settings.import.error.nothing_selected'));
        }

        $options = new IssueSettingsImportOptions(
            managers: $request->request->getBoolean('include_managers'),
            replace: $request->request->getString('mode') === 'replace',
        );
        $result = $this->importer->import($parsed, $centre, $typeIndexes, $priorityIndexes, $options);

        $this->activityLog->log('issue_settings.imported', [
            'entityId'           => $centre->getId()->toRfc4122(),
            'mode'               => $options->replace ? 'replace' : 'ignore',
            'managers'           => $options->managers,
            'typesCreated'       => $result->typesCreated,
            'typesUpdated'       => $result->typesUpdated,
            'prioritiesCreated'  => $result->prioritiesCreated,
            'prioritiesUpdated'  => $result->prioritiesUpdated,
            'unresolvedTeachers' => count($result->unresolved),
        ]);

        @unlink($this->tempPath($importId));
        $request->getSession()->remove('issue_settings_import_id');

        $this->addFlash('success', $this->translator->trans('issue_settings.import.flash.summary', [
            '%types%'      => $result->typesCreated + $result->typesUpdated,
            '%priorities%' => $result->prioritiesCreated + $result->prioritiesUpdated,
            '%ignored%'    => $result->typesIgnored + $result->prioritiesIgnored,
        ], 'admin'));
        if ($result->unresolved !== []) {
            $this->addFlash('error', $this->translator->trans('issue_settings.import.flash.unresolved', [
                '%count%'    => count($result->unresolved),
                '%teachers%' => implode(', ', $result->unresolved),
            ], 'admin'));
        }

        return $this->redirectToRoute('app_centre_issue_settings', ['centreId' => $centreId]);
    }

    /**
     * @param IssueSettingsFile $parsed
     */
    private function renderPreview(EducationalCentre $centre, string $importId, array $parsed, ?string $error): Response
    {
        if ($error !== null) {
            $this->addFlash('error', $error);
        }

        return $this->render('admin/issue_settings/import_preview.html.twig', [
            'centre'   => $centre,
            'importId' => $importId,
            'file'     => $parsed,
            'preview'  => $this->importer->preview($parsed, $centre),
        ]);
    }

    // ── Ayudas ────────────────────────────────────────────────────────────────

    /**
     * Los tipos en orden de árbol (cada nodo seguido de sus hijos) con su profundidad.
     *
     * @param list<IssueType> $all
     * @return list<array{0: IssueType, 1: int}>
     */
    private function flatten(array $all): array
    {
        $byParent = [];
        foreach ($all as $type) {
            $byParent[$type->getParent()?->getId()->toRfc4122() ?? ''][] = $type;
        }

        $out  = [];
        $walk = function (string $parentId, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $type) {
                $out[] = [$type, $depth];
                $walk($type->getId()->toRfc4122(), $depth + 1);
            }
        };
        $walk('', 0);

        return $out;
    }

    /**
     * Los responsables que un tipo hereda de sus ascendentes (sin los asignados a él).
     *
     * @return list<Teacher>
     */
    private function inheritedManagers(IssueType $type): array
    {
        $own       = [];
        foreach ($type->getManagers() as $manager) {
            $own[$manager->getId()->toRfc4122()] = true;
        }

        return array_values(array_filter($type->getEffectiveManagers(), static fn (Teacher $t): bool => !isset($own[$t->getId()->toRfc4122()])));
    }

    /**
     * Los docentes del curso activo y el personal no docente del centro que pueden ser responsables, y los que ya lo son
     * aunque ya no estén en el curso.
     *
     * @return array<string, Teacher>
     */
    private function selectableTeachers(EducationalCentre $centre, IssueType $type): array
    {
        $teachers = [];
        foreach ($this->assignees->candidates($centre) as $teacher) {
            $teachers[$teacher->getId()->toRfc4122()] = $teacher;
        }
        foreach ($type->getManagers() as $manager) {
            $teachers[$manager->getId()->toRfc4122()] ??= $manager;
        }

        return $teachers;
    }

    /**
     * Posiciones válidas (0 ≤ i < $count) de una lista que llega del formulario.
     *
     * @param array<mixed> $raw
     * @return list<int>
     */
    private function indexes(array $raw, int $count): array
    {
        $indexes = [];
        foreach ($raw as $value) {
            if ((is_string($value) || is_int($value)) && ctype_digit((string) $value) && (int) $value < $count) {
                $indexes[(int) $value] = (int) $value;
            }
        }

        return array_values($indexes);
    }

    private function days(string $raw): int
    {
        return ctype_digit(trim($raw)) ? min((int) trim($raw), 365) : 0;
    }

    private function cleanName(string $raw, int $maxLength): ?string
    {
        $raw = trim($raw);

        return $raw === '' ? null : mb_substr($raw, 0, $maxLength);
    }

    private function tempPath(string $importId): string
    {
        return (string) $this->getParameter('kernel.project_dir') . '/var/tmp/issue-settings-imports/' . $importId . '.json';
    }

    private function checkCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function requireCentre(string $centreId): EducationalCentre
    {
        $centre = $this->centres->findByIdWithActiveYear($centreId);
        if ($centre === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(EducationalCentreVoter::SECTION, $centre);

        return $centre;
    }

    private function requireType(string $id, EducationalCentre $centre): IssueType
    {
        return $this->types->findByCentreAndId($centre, $id) ?? throw $this->createNotFoundException();
    }

    private function requirePriority(string $id, EducationalCentre $centre): IssuePriority
    {
        return $this->priorities->findByCentreAndId($centre, $id) ?? throw $this->createNotFoundException();
    }
}
