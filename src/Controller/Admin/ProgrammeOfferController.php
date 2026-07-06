<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Service\ActivityLogService;
use App\Service\ImportOptions;
use App\Service\ProgrammeOfferExporter;
use App\Service\ProgrammeOfferImporter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centros/{centreId}/offer')]
class ProgrammeOfferController extends AbstractController
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TranslatorInterface $translator,
        private readonly ProgrammeOfferExporter $exporter,
        private readonly ProgrammeOfferImporter $importer,
        private readonly TenantContext $tenantContext,
        private readonly ActivityLogService $activityLog,
    ) {}

    #[Route('/export', name: 'app_admin_offer_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        $centre = $this->requireCentreWithActiveYear($centreId);
        $year   = $centre->getActiveAcademicYear();
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $data     = $this->exporter->export($year);
        $filename = 'offer-' . $centre->getCode() . '-' . $year->getName() . '.json';
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], true);
    }

    #[Route('/import', name: 'app_admin_offer_import')]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentreForWrite($centreId);

        if (!$request->isMethod('POST')) {
            return $this->render('admin/offer/import.html.twig', ['centre' => $centre]);
        }

        if (!$this->isCsrfTokenValid('import_offer', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('json');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('offer.import.error.no_file'));
            return $this->render('admin/offer/import.html.twig', ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $this->addFlash('error', $this->t('offer.import.error.invalid_json'));
            return $this->render('admin/offer/import.html.twig', ['centre' => $centre]);
        }

        $importYear = $centre->getActiveAcademicYear();
        if ($importYear === null) {
            throw $this->createNotFoundException();
        }

        $options = new ImportOptions(
            importTutors:   $request->request->has('import_tutors'),
            importTeachers: $request->request->has('import_teachers'),
        );

        /** @var array<string, mixed> $decoded */
        $stats = $this->importer->import($decoded, $importYear, $options);

        $this->activityLog->log('programme_offer.imported', [
            'centreId'   => $centre->getId()->toRfc4122(),
            'yearId'     => $importYear->getId()->toRfc4122(),
            'programmes' => $stats['programmes'],
            'levels'     => $stats['levels'],
            'groups'     => $stats['groups'],
        ]);

        $summary = $this->translator->trans('offer.import.flash.summary', [
            '%programmes%' => $stats['programmes'],
            '%levels%'     => $stats['levels'],
            '%groups%'     => $stats['groups'],
        ], 'admin');

        if (!empty($stats['missing_teachers'])) {
            $summary .= ' ' . $this->translator->trans('offer.import.flash.missing_teachers', [
                '%teachers%' => implode(', ', array_map(
                    static fn(string $u) => '«' . $u . '»',
                    $stats['missing_teachers'],
                )),
            ], 'admin');
        }

        $this->addFlash('success', $summary);

        return $this->redirectToRoute('app_admin_offer_index', ['centreId' => $centreId]);
    }

    #[Route('', name: 'app_admin_offer_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/offer/index.html.twig', ['centre' => $centre]);
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

    private function requireCentreWithActiveYear(string $centreId): EducationalCentre
    {
        $centre = $this->requireCentre($centreId);
        if ($centre->getActiveAcademicYear() === null) {
            throw $this->createNotFoundException();
        }

        return $centre;
    }

    private function requireCentreForWrite(string $centreId): EducationalCentre
    {
        $centre = $this->requireCentreWithActiveYear($centreId);
        if ($this->tenantContext->isViewingNonActiveYear($centre)) {
            throw $this->createAccessDeniedException('Write operations are not allowed when viewing a past academic year.');
        }

        return $centre;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
