<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EducationalCentre;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\TenantContext;
use App\Service\TimeSlotExporter;
use App\Service\TimeSlotImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/tramos-horarios')]
class TimeSlotController extends AbstractController
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly TenantContext $tenantContext,
        private readonly TimeSlotExporter $exporter,
        private readonly TimeSlotImporter $importer,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_centre_time_slots_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/time_slot/index.html.twig', ['centre' => $centre]);
    }

    #[Route('/export', name: 'app_centre_time_slots_export', methods: ['GET'])]
    public function export(string $centreId): JsonResponse
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $data     = $this->exporter->export($year);
        $filename = 'tramos-horarios-' . $centre->getCode() . '.json';
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new JsonResponse($json, Response::HTTP_OK, [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], true);
    }

    #[Route('/importar', name: 'app_centre_time_slots_import')]
    public function import(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);
        $year   = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        if (!$request->isMethod('POST')) {
            return $this->render('admin/time_slot/import.html.twig', ['centre' => $centre]);
        }

        if (!$this->isCsrfTokenValid('import_time_slots', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('json');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('time_slot.import.error.no_file'));

            return $this->render('admin/time_slot/import.html.twig', ['centre' => $centre]);
        }

        $content = (string) file_get_contents($file->getPathname());
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            $this->addFlash('error', $this->t('time_slot.import.error.invalid_json'));

            return $this->render('admin/time_slot/import.html.twig', ['centre' => $centre]);
        }

        /** @var array<string, mixed> $decoded */
        $stats = $this->importer->import($decoded, $year, $request->request->has('replace_existing'));

        $this->addFlash('success', $this->translator->trans('time_slot.import.flash.summary', [
            '%count%' => $stats['time_slots'],
        ], 'admin'));

        return $this->redirectToRoute('app_centre_time_slots_index', ['centreId' => $centreId]);
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

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
