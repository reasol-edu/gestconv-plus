<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\TranslatorTrait;
use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\AcademicYearRepository;
use App\Repository\EducationalCentreRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/centro/{centreId}/cursos')]
class AcademicYearController extends AbstractController
{
    use TranslatorTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EducationalCentreRepository $centres,
        private readonly AcademicYearRepository $years,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
    ) {}

    #[Route('', name: 'app_centre_years_index')]
    public function index(string $centreId): Response
    {
        $centre = $this->requireCentre($centreId);

        return $this->render('admin/academic_year/index.html.twig', [
            'centre' => $centre,
            'years'  => $this->years->findByCentreOrderedByName($centre),
        ]);
    }

    #[Route('/nuevo', name: 'app_centre_years_add', methods: ['POST'])]
    public function add(string $centreId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        if (!$this->isCsrfTokenValid('add_year_' . $centreId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim($request->request->getString('name'));
        $from = $this->parseDay($request->request->getString('start_date'));
        $to   = $this->parseDay($request->request->getString('end_date'));

        if ($name === '') {
            $this->addFlash('error', $this->t('year.flash.name_required'));
        } elseif (($from === null) !== ($to === null) || ($from !== null && $to !== null && $from >= $to)) {
            $this->addFlash('error', $this->t('year.flash.dates_invalid'));
        } else {
            $year = (new AcademicYear())
                ->setName($name)
                ->setEducationalCentre($centre);
            // Sin fechas, las que salen del nombre («2026-2027» → 15/09/2026 a 14/09/2027).
            if ($from !== null && $to !== null) {
                $year->setDates($from, $to);
            }

            $this->em->persist($year);
            $this->em->flush();

            $this->activityLog->log('academic_year.created', [
                'entityId' => $year->getId()->toRfc4122(),
                'centreId' => $centre->getId()->toRfc4122(),
                'name'     => $year->getName(),
            ]);

            $this->addFlash('success', $this->t('year.flash.added'));
        }

        return $this->redirectToRoute('app_centre_years_index', ['centreId' => $centreId]);
    }

    #[Route('/{yearId}/editar', name: 'app_centre_years_edit')]
    public function edit(string $centreId, string $yearId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        $year = $this->years->findByCentreAndId($centre, $yearId);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_year_' . $yearId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $name = trim($request->request->getString('name'));
            $from = $this->parseDay($request->request->getString('start_date'));
            $to   = $this->parseDay($request->request->getString('end_date'));

            if ($name === '') {
                $errors['name'] = $this->t('year.error.name_required');
            }
            if ($from === null || $to === null) {
                $errors['dates'] = $this->t('year.error.dates_required');
            } elseif ($from >= $to) {
                $errors['dates'] = $this->t('year.error.dates_order');
            }

            if ($errors === [] && $from !== null && $to !== null) {
                $year->setName($name)->setDates($from, $to);
                $this->em->flush();

                $this->addFlash('success', $this->t('year.flash.saved'));

                return $this->redirectToRoute('app_centre_years_index', ['centreId' => $centreId]);
            }
        }

        return $this->render('admin/academic_year/edit.html.twig', [
            'centre' => $centre,
            'year'   => $year,
            'errors' => $errors,
        ]);
    }

    #[Route('/{yearId}/eliminar', name: 'app_centre_years_delete', methods: ['POST'])]
    public function delete(string $centreId, string $yearId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        if (!$this->isCsrfTokenValid('delete_year_' . $yearId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $year = $this->years->findByCentreAndId($centre, $yearId);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        if ($centre->getActiveAcademicYear() === $year) {
            $this->addFlash('error', $this->t('year.flash.delete_active_error'));
        } else {
            $this->em->remove($year);
            $this->em->flush();
            $this->addFlash('success', $this->t('year.flash.deleted'));
        }

        return $this->redirectToRoute('app_centre_years_index', ['centreId' => $centreId]);
    }

    #[Route('/{yearId}/activar', name: 'app_centre_years_activate', methods: ['POST'])]
    public function activate(string $centreId, string $yearId, Request $request): Response
    {
        $centre = $this->requireCentre($centreId);

        if (!$this->isCsrfTokenValid('activate_year_' . $yearId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $year = $this->years->findByCentreAndId($centre, $yearId);
        if ($year === null) {
            throw $this->createNotFoundException();
        }

        $centre->setActiveAcademicYear($year);
        $this->em->flush();

        $this->addFlash('success', $this->t('year.flash.activated'));

        return $this->redirectToRoute('app_centre_years_index', ['centreId' => $centreId]);
    }

    /** Un día enviado por un `<input type="date">` (Y-m-d); null si está vacío o no es una fecha real. */
    private function parseDay(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $day !== false && $day->format('Y-m-d') === $raw ? $day : null;
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
}
