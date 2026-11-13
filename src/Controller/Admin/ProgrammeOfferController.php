<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use App\Service\OfferExporter;
use App\Service\OfferImporter;
use App\Service\OfferImportOptions;
use App\Service\TenantContext;
use App\Service\UploadLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Oferta formativa del centro (cursos y grupos), su exportación a JSON y su importación con vista previa: se elige qué
 * cursos y grupos importar, qué información de los grupos (tutores, materias, color) y si lo que ya existe se sustituye
 * o se ignora. Se importa siempre en el curso académico activo.
 *
 * @phpstan-import-type OfferFile from OfferImporter
 */
#[Route('/centro/{centreId}/offer')]
class ProgrammeOfferController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly OfferExporter $exporter,
        private readonly OfferImporter $importer,
        private readonly UploadLimits $uploadLimits,
        private readonly ActivityLogService $activityLog,
    ) {}

    #[Route('', name: 'app_centre_offer_index')]
    public function index(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/offer/index.html.twig', [
            'centre'    => $centre,
            // Grupo que abre el enlace del aviso de «grupos sin tutoría»; el componente lo valida y lo ignora si no procede.
            'openGroup' => $request->query->getString('grupo'),
        ]);
    }

    /** Descarga la oferta del curso que se está viendo (también un curso pasado, para llevarla al curso nuevo). */
    #[Route('/export', name: 'app_centre_offer_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $json = json_encode($this->exporter->export($centre, $year), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="oferta-formativa-' . $centre->getCode() . '-' . $year->getName() . '.json"',
        ], true);
    }

    /** Paso 1: elegir el fichero. Paso 2 (POST): vista previa y selección. */
    #[Route('/import', name: 'app_centre_offer_import', methods: ['GET', 'POST'])]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->requireImportableCentre($centreId);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/offer/import.html.twig', ['centre' => $centre]);
        }

        $this->checkCsrf($request, 'import_offer');

        $file    = $request->files->get('json');
        $problem = $this->uploadLimits->fileProblem($file);
        if ($problem !== null) {
            $this->addFlash('error', $problem);

            return $this->render('admin/offer/import.html.twig', ['centre' => $centre]);
        }
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('offer.import.error.no_file'));

            return $this->render('admin/offer/import.html.twig', ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());
        $parsed  = $this->importer->parse(json_decode($content, true));
        if ($parsed === null) {
            $this->addFlash('error', $this->t('offer.import.error.invalid'));

            return $this->render('admin/offer/import.html.twig', ['centre' => $centre]);
        }
        if ($parsed['courses'] === []) {
            $this->addFlash('error', $this->t('offer.import.error.empty'));

            return $this->render('admin/offer/import.html.twig', ['centre' => $centre]);
        }

        // El fichero se guarda hasta la confirmación, igual que en la importación de estudiantes.
        $importId = Uuid::v4()->toRfc4122();
        $path     = $this->tempPath($importId);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
        $request->getSession()->set('offer_import_id', $importId);

        return $this->renderPreview($centre, $importId, $parsed, null);
    }

    /** Paso 3: aplicar la selección y las opciones elegidas en la vista previa. */
    #[Route('/import/confirmar', name: 'app_centre_offer_import_confirm', methods: ['POST'])]
    public function confirm(string $centreId, Request $request): Response
    {
        $centre = $this->requireImportableCentre($centreId);
        $this->checkCsrf($request, 'import_offer_confirm');

        $importId = $request->request->getString('import_id');
        $parsed   = null;
        if (Uuid::isValid($importId) && $importId === $request->getSession()->get('offer_import_id') && is_file($this->tempPath($importId))) {
            $parsed = $this->importer->parse(json_decode((string) file_get_contents($this->tempPath($importId)), true));
        }
        if ($parsed === null) {
            $this->addFlash('error', $this->t('offer.import.error.expired'));

            return $this->redirectToRoute('app_centre_offer_import', ['centreId' => $centreId]);
        }

        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        // Los índices que llegan del formulario se validan contra el fichero: solo cuentan los que existen.
        $courseIndexes = $this->indexes($request->request->all('courses'), count($parsed['courses']));
        $groupIndexes  = [];
        foreach ($request->request->all('groups') as $courseIndex => $list) {
            if (is_array($list) && isset($parsed['courses'][(int) $courseIndex])) {
                $groupIndexes[(int) $courseIndex] = $this->indexes($list, count($parsed['courses'][(int) $courseIndex]['groups']));
            }
        }

        if ($courseIndexes === [] && array_filter($groupIndexes) === []) {
            return $this->renderPreview($centre, $importId, $parsed, $this->t('offer.import.error.nothing_selected'));
        }

        $options = new OfferImportOptions(
            tutors: $request->request->getBoolean('include_tutors'),
            subjects: $request->request->getBoolean('include_subjects'),
            color: $request->request->getBoolean('include_color'),
            replace: $request->request->getString('mode') === 'replace',
        );
        $result = $this->importer->import($parsed, $year, $courseIndexes, $groupIndexes, $options);

        $this->activityLog->log('offer.imported', [
            'entityId'           => $centre->getId()->toRfc4122(),
            'academicYear'       => $year->getName(),
            'mode'               => $options->replace ? 'replace' : 'ignore',
            'tutors'             => $options->tutors,
            'subjects'           => $options->subjects,
            'color'              => $options->color,
            'coursesCreated'     => $result->coursesCreated,
            'coursesUpdated'     => $result->coursesUpdated,
            'groupsCreated'      => $result->groupsCreated,
            'groupsUpdated'      => $result->groupsUpdated,
            'subjectsAdded'      => $result->subjectsAdded,
            'subjectsRemoved'    => $result->subjectsRemoved,
            'subjectsKept'       => $result->subjectsKept,
            'unresolvedTeachers' => count($result->unresolved),
        ]);

        @unlink($this->tempPath($importId));
        $request->getSession()->remove('offer_import_id');

        $this->addFlash('success', $this->translator->trans('offer.import.flash.summary', [
            '%courses%' => $result->coursesCreated + $result->coursesUpdated,
            '%groups%'  => $result->groupsCreated + $result->groupsUpdated,
            '%ignored%' => $result->coursesIgnored + $result->groupsIgnored,
            '%added%'   => $result->subjectsAdded,
            '%removed%' => $result->subjectsRemoved,
        ], 'admin'));
        if ($result->subjectsKept > 0) {
            $this->addFlash('success', $this->translator->trans('offer.import.flash.kept', ['%count%' => $result->subjectsKept], 'admin'));
        }
        if ($result->unresolved !== []) {
            $this->addFlash('error', $this->translator->trans('offer.import.flash.unresolved', [
                '%count%'    => count($result->unresolved),
                '%teachers%' => implode(', ', $result->unresolved),
            ], 'admin'));
        }

        return $this->redirectToRoute('app_centre_offer_index', ['centreId' => $centreId]);
    }

    /**
     * @param OfferFile $parsed
     */
    private function renderPreview(EducationalCentre $centre, string $importId, array $parsed, ?string $error): Response
    {
        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            throw $this->createNotFoundException();
        }
        if ($error !== null) {
            $this->addFlash('error', $error);
        }

        return $this->render('admin/offer/import_preview.html.twig', [
            'centre'   => $centre,
            'year'     => $year,
            'importId' => $importId,
            'file'     => $parsed,
            'preview'  => $this->importer->preview($parsed, $year),
        ]);
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

    private function tempPath(string $importId): string
    {
        return (string) $this->getParameter('kernel.project_dir') . '/var/tmp/offer-imports/' . $importId . '.json';
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

    /** Importar escribe en el curso activo: no se permite mientras se está viendo otro curso. */
    private function requireImportableCentre(string $centreId): EducationalCentre
    {
        $centre = $this->requireCentre($centreId);
        if ($this->tenantContext->isViewingNonActiveYear($centre)) {
            throw $this->createAccessDeniedException('Write operations are not allowed while viewing a non-active academic year.');
        }

        return $centre;
    }
}
