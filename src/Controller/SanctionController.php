<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionMeasureRepository;
use App\Repository\SanctionRepository;
use App\Repository\StudentRepository;
use App\Security\Voter\SanctionVoter;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/sanciones')]
class SanctionController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly SanctionRepository $sanctions,
        private readonly SanctionMeasureRepository $measures,
        private readonly IncidentReportRepository $reports,
        private readonly StudentRepository $students,
        private readonly GroupRepository $groups,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_sanctions_index')]
    public function index(): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $sanctions = $this->sanctions->findByCentreForViewer($centre, $user);

        return $this->render('sanction/index.html.twig', [
            'centre'    => $centre,
            'sanctions' => $sanctions,
        ]);
    }

    #[Route('/nueva', name: 'app_sanctions_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        if (!$user->isAdmin() && !$centre->getAdmins()->contains($user) && !$centre->getCommitteeMembers()->contains($user)) {
            throw $this->createAccessDeniedException();
        }

        $studentId = $request->query->getString('studentId');
        $groupId   = $request->query->getString('groupId');

        $measuresByCategory = $this->groupMeasuresByCategory(
            $this->measures->findByCentreActive($centre)
        );

        // Step 2 — full form (studentId + groupId in query string)
        if ($studentId !== '' && $groupId !== '') {
            $student     = $this->students->findById($studentId);
            $groupResult = $this->groups->find($groupId);
            $group       = $groupResult instanceof \App\Entity\Group ? $groupResult : null;

            if ($student === null || $group === null) {
                return $this->redirectToRoute('app_sanctions_new');
            }

            $eligibleReports = $this->sanctions->findEligibleReports($student, $group);
            $errors          = [];

            if ($request->isMethod('POST') && $request->request->getString('_step') !== 'select') {
                if (!$this->isCsrfTokenValid('new_sanction', $request->request->getString('_token'))) {
                    throw $this->createAccessDeniedException();
                }

                $reportIds       = $request->request->all('reports');
                $measureIds      = $request->request->all('measures');
                $details         = trim($request->request->getString('details'));
                $noMeasure       = $request->request->getBoolean('no_measure_applied');
                $noMeasureReason = trim($request->request->getString('no_measure_reason'));
                $effectiveFromRaw = trim($request->request->getString('effective_from'));
                $effectiveToRaw   = trim($request->request->getString('effective_to'));

                if (empty($reportIds)) {
                    $errors[] = $this->t('sanction.error.no_reports');
                }

                if ($details === '') {
                    $errors[] = $this->t('sanction.error.details_required');
                }

                if (!$noMeasure && empty($measureIds)) {
                    $errors[] = $this->t('sanction.error.no_measures');
                }

                if ($noMeasure && $noMeasureReason === '') {
                    $errors[] = $this->t('sanction.error.no_measure_reason_required');
                }

                /** @var list<\App\Entity\SanctionMeasure> $selectedMeasures */
                $selectedMeasures = [];
                if (!$noMeasure) {
                    foreach ($measureIds as $mid) {
                        if (!is_string($mid)) {
                            continue;
                        }
                        $m = $this->measures->findById($mid);
                        if ($m !== null && $m->getEducationalCentre() === $centre) {
                            $selectedMeasures[] = $m;
                        }
                    }
                }

                $requiresDates = false;
                foreach ($selectedMeasures as $m) {
                    if ($m->hasDateRange()) {
                        $requiresDates = true;
                        break;
                    }
                }

                $effectiveFrom = null;
                $effectiveTo   = null;

                if ($requiresDates) {
                    if ($effectiveFromRaw === '') {
                        $errors[] = $this->t('sanction.error.effective_from_required');
                    } else {
                        $effectiveFrom = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveFromRaw) ?: null;
                        if ($effectiveFrom === null) {
                            $errors[] = $this->t('sanction.error.effective_from_invalid');
                        }
                    }
                    if ($effectiveToRaw === '') {
                        $errors[] = $this->t('sanction.error.effective_to_required');
                    } else {
                        $effectiveTo = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveToRaw) ?: null;
                        if ($effectiveTo === null) {
                            $errors[] = $this->t('sanction.error.effective_to_invalid');
                        }
                    }
                }

                if (empty($errors)) {
                    $academicYear = $group->getProgrammeYear()->getProgramme()->getAcademicYear();

                    $sanction = (new Sanction())
                        ->setAcademicYear($academicYear)
                        ->setStudent($student)
                        ->setGroup($group)
                        ->setRegisteredBy($user)
                        ->setDetails($details)
                        ->setNoMeasureApplied($noMeasure)
                        ->setNoMeasureReason($noMeasure ? ($noMeasureReason ?: null) : null)
                        ->setEffectiveFrom($requiresDates ? $effectiveFrom : null)
                        ->setEffectiveTo($requiresDates ? $effectiveTo : null);

                    foreach ($selectedMeasures as $m) {
                        $sanction->addMeasure($m);
                    }

                    $this->em->persist($sanction);

                    // Link selected reports to this sanction
                    foreach ($reportIds as $rid) {
                        if (!is_string($rid)) {
                            continue;
                        }
                        $report = $this->reports->findById($rid);
                        if ($report === null
                            || $report->getStudent() !== $student
                            || $report->getGroup() !== $group
                            || $report->isPrescribed()
                            || $report->getSanction() !== null) {
                            continue;
                        }
                        $report->setSanction($sanction);
                    }

                    $this->em->flush();

                    $this->addFlash('success', $this->t('sanction.flash.created'));

                    return $this->redirectToRoute('app_sanctions_show', ['id' => $sanction->getId()->toRfc4122()]);
                }

                return $this->render('sanction/new.html.twig', [
                    'centre'             => $centre,
                    'student'            => $student,
                    'group'              => $group,
                    'eligibleReports'    => $eligibleReports,
                    'measuresByCategory' => $measuresByCategory,
                    'errors'             => $errors,
                    'formData'           => [
                        'reports'          => array_values(array_filter($reportIds, 'is_string')),
                        'measureIds'       => array_values(array_filter($measureIds, 'is_string')),
                        'details'          => $details,
                        'noMeasure'        => $noMeasure,
                        'noMeasureReason'  => $noMeasureReason,
                        'effectiveFrom'    => $effectiveFromRaw,
                        'effectiveTo'      => $effectiveToRaw,
                    ],
                ]);
            }

            return $this->render('sanction/new.html.twig', [
                'centre'             => $centre,
                'student'            => $student,
                'group'              => $group,
                'eligibleReports'    => $eligibleReports,
                'measuresByCategory' => $measuresByCategory,
                'errors'             => [],
                'formData'           => [],
            ]);
        }

        // Step 1 — paginated student list with report stats
        $search  = $request->query->getString('search');
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = 20;
        $stats   = $this->sanctions->findStudentStatsForCentre($centre, $search, $page, $perPage);

        return $this->render('sanction/new_select_student.html.twig', [
            'centre'   => $centre,
            'rows'     => $stats['rows'],
            'total'    => $stats['total'],
            'page'     => $page,
            'perPage'  => $perPage,
            'search'   => $search,
        ]);
    }

    #[Route('/{id}', name: 'app_sanctions_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SanctionVoter::VIEW, $sanction);

        return $this->render('sanction/show.html.twig', [
            'centre'   => $centre,
            'sanction' => $sanction,
        ]);
    }

    #[Route('/{id}/editar', name: 'app_sanctions_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SanctionVoter::EDIT, $sanction);

        $measuresByCategory = $this->groupMeasuresByCategory(
            $this->measures->findByCentreActive($centre)
        );

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_sanction_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $reportIds       = $request->request->all('reports');
            $measureIds      = $request->request->all('measures');
            $details         = trim($request->request->getString('details'));
            $noMeasure       = $request->request->getBoolean('no_measure_applied');
            $noMeasureReason = trim($request->request->getString('no_measure_reason'));
            $effectiveFromRaw = trim($request->request->getString('effective_from'));
            $effectiveToRaw   = trim($request->request->getString('effective_to'));

            if (empty($reportIds)) {
                $errors[] = $this->t('sanction.error.no_reports');
            }

            if ($details === '') {
                $errors[] = $this->t('sanction.error.details_required');
            }

            if (!$noMeasure && empty($measureIds)) {
                $errors[] = $this->t('sanction.error.no_measures');
            }

            if ($noMeasure && $noMeasureReason === '') {
                $errors[] = $this->t('sanction.error.no_measure_reason_required');
            }

            /** @var list<\App\Entity\SanctionMeasure> $selectedMeasures */
            $selectedMeasures = [];
            if (!$noMeasure) {
                foreach ($measureIds as $mid) {
                    if (!is_string($mid)) {
                        continue;
                    }
                    $m = $this->measures->findById($mid);
                    if ($m !== null && $m->getEducationalCentre() === $centre) {
                        $selectedMeasures[] = $m;
                    }
                }
            }

            $requiresDates = false;
            foreach ($selectedMeasures as $m) {
                if ($m->hasDateRange()) {
                    $requiresDates = true;
                    break;
                }
            }

            $effectiveFrom = null;
            $effectiveTo   = null;

            if ($requiresDates) {
                if ($effectiveFromRaw === '') {
                    $errors[] = $this->t('sanction.error.effective_from_required');
                } else {
                    $effectiveFrom = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveFromRaw) ?: null;
                    if ($effectiveFrom === null) {
                        $errors[] = $this->t('sanction.error.effective_from_invalid');
                    }
                }
                if ($effectiveToRaw === '') {
                    $errors[] = $this->t('sanction.error.effective_to_required');
                } else {
                    $effectiveTo = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveToRaw) ?: null;
                    if ($effectiveTo === null) {
                        $errors[] = $this->t('sanction.error.effective_to_invalid');
                    }
                }
            }

            if (empty($errors)) {
                $sanction->setDetails($details)
                         ->setNoMeasureApplied($noMeasure)
                         ->setNoMeasureReason($noMeasure ? ($noMeasureReason ?: null) : null)
                         ->setEffectiveFrom($requiresDates ? $effectiveFrom : null)
                         ->setEffectiveTo($requiresDates ? $effectiveTo : null);

                // Replace measures
                foreach ($sanction->getMeasures()->toArray() as $m) {
                    $sanction->removeMeasure($m);
                }
                foreach ($selectedMeasures as $m) {
                    $sanction->addMeasure($m);
                }

                // Replace report associations
                foreach ($sanction->getReports()->toArray() as $r) {
                    /** @var IncidentReport $r */
                    $r->setSanction(null);
                }

                foreach ($reportIds as $rid) {
                    if (!is_string($rid)) {
                        continue;
                    }
                    $report = $this->reports->findById($rid);
                    if ($report === null
                        || $report->getStudent() !== $sanction->getStudent()
                        || $report->getGroup() !== $sanction->getGroup()
                        || $report->isPrescribed()
                        || ($report->getSanction() !== null && $report->getSanction() !== $sanction)) {
                        continue;
                    }
                    $report->setSanction($sanction);
                }

                $this->em->flush();

                $this->addFlash('success', $this->t('sanction.flash.updated'));

                return $this->redirectToRoute('app_sanctions_show', ['id' => $id]);
            }
        }

        // For edit: eligible = already linked + newly eligible for this student/group
        $allEligible = $this->sanctions->findEligibleReports(
            $sanction->getStudent(),
            $sanction->getGroup()
        );

        // Include already linked reports
        $linked   = $sanction->getReports()->toArray();
        $linkedIds = array_map(
            static fn(\App\Entity\IncidentReport $r): string => $r->getId()->toRfc4122(),
            $linked
        );
        $availableReports = array_merge(
            $linked,
            array_filter(
                $allEligible,
                static fn(\App\Entity\IncidentReport $r): bool => !in_array($r->getId()->toRfc4122(), $linkedIds, true)
            )
        );

        return $this->render('sanction/edit.html.twig', [
            'centre'             => $centre,
            'sanction'           => $sanction,
            'availableReports'   => $availableReports,
            'measuresByCategory' => $measuresByCategory,
            'errors'             => $errors,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_sanctions_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SanctionVoter::DELETE, $sanction);

        if (!$this->isCsrfTokenValid('delete_sanction_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Unlink reports before removal (cascade not set for this side)
        foreach ($sanction->getReports()->toArray() as $r) {
            /** @var IncidentReport $r */
            $r->setSanction(null);
        }

        $this->em->remove($sanction);
        $this->em->flush();

        $this->addFlash('success', $this->t('sanction.flash.deleted'));

        return $this->redirectToRoute('app_sanctions_index');
    }

    /**
     * @param list<\App\Entity\SanctionMeasure> $measures
     * @return list<array{category: \App\Entity\SanctionMeasureCategory, measures: list<\App\Entity\SanctionMeasure>}>
     */
    private function groupMeasuresByCategory(array $measures): array
    {
        $groups = [];
        foreach ($measures as $m) {
            $catId = $m->getCategory()->getId()->toRfc4122();
            if (!isset($groups[$catId])) {
                $groups[$catId] = ['category' => $m->getCategory(), 'measures' => []];
            }
            $groups[$catId]['measures'][] = $m;
        }

        return array_values($groups);
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
