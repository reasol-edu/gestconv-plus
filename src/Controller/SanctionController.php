<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;
use App\Entity\GroupTeacher;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\SanctionObservation;
use App\Entity\SanctionTask;
use App\Entity\Teacher;
use App\Repository\CommunicationRepository;
use App\Repository\GroupRepository;
use App\Repository\IncidentReportObservationRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\SanctionMeasureRepository;
use App\Repository\SanctionObservationRepository;
use App\Repository\SanctionRepository;
use App\Repository\StudentRepository;
use App\Security\Voter\SanctionObservationVoter;
use App\Security\Voter\SanctionVoter;
use App\Service\ActivityLogService;
use App\Service\EntityChangeTracker;
use App\Service\IncidentEmailNotifier;
use App\Service\AppSettingsInterface;
use App\Service\PdfHeaderBuilder;
use App\Service\PdfRenderer;
use App\Service\SanctionTaskGenerator;
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
    /** @var list<string> */
    private const LOGGED_SANCTION_FIELDS = [
        'details',
        'calendarLabel',
        'noMeasureApplied',
        'noMeasureReason',
        'effectiveFrom',
        'effectiveTo',
        'measuresEffective',
        'familyClaimed',
        'familyClaimAttitude',
        'registeredInSeneca',
    ];

    /** @var list<string> */
    private const LOGGED_OBSERVATION_FIELDS = ['text', 'registeredAt'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly SanctionRepository $sanctions,
        private readonly SanctionMeasureRepository $measures,
        private readonly IncidentReportRepository $reports,
        private readonly StudentRepository $students,
        private readonly GroupRepository $groups,
        private readonly CommunicationRepository $communications,
        private readonly IncidentReportObservationRepository $reportObservations,
        private readonly SanctionObservationRepository $sanctionObservations,
        private readonly IncidentEmailNotifier $notifier,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
        private readonly EntityChangeTracker $changeTracker,
        private readonly PdfRenderer $pdfRenderer,
        private readonly PdfHeaderBuilder $pdfHeaderBuilder,
        private readonly AppSettingsInterface $settings,
        private readonly SanctionTaskGenerator $taskGenerator,
    ) {}

    #[Route('', name: 'app_sanctions_index')]
    public function index(Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('sanction/index.html.twig', [
            'centre'           => $centre,
            'pendingTasksOnly' => $request->query->getBoolean('pendingTasksOnly'),
        ]);
    }

    #[Route('/nueva', name: 'app_sanctions_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $this->denyIfViewingPastYear($centre);

        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(SanctionVoter::CREATE, $centre);

        $studentId = $request->query->getString('studentId');
        $groupId   = $request->query->getString('groupId');

        $measuresByCategory = $this->groupMeasuresByCategory(
            $this->measures->findByCentreActive($centre)
        );

        // Step 2 — full form (studentId + groupId in query string)
        if ($studentId !== '' && $groupId !== '') {
            $student = $this->students->findById($studentId);
            $group   = $this->groups->findByIdAndCentre($groupId, $centre);

            if ($student === null || $group === null) {
                return $this->redirectToRoute('app_sanctions_new');
            }

            $eligibleReports = $this->sanctions->findEligibleReports($student, $group);
            $errors          = [];

            if ($request->isMethod('POST') && $request->request->getString('_step') !== 'select') {
                if (!$this->isCsrfTokenValid('new_sanction', $request->request->getString('_token'))) {
                    throw $this->createAccessDeniedException();
                }

                $reportIds              = $request->request->all('reports');
                $measureIds             = $request->request->all('measures');
                $details                = trim($request->request->getString('details'));
                $calendarLabel          = trim($request->request->getString('calendar_label')) ?: null;
                $noMeasure              = $request->request->getBoolean('no_measure_applied');
                $noMeasureReason        = trim($request->request->getString('no_measure_reason'));
                $effectiveFromRaw       = trim($request->request->getString('effective_from'));
                $effectiveToRaw         = trim($request->request->getString('effective_to'));
                $measuresEffective      = $this->parseBoolField($request->request->getString('measures_effective'));
                $familyClaimed          = $this->parseBoolField($request->request->getString('family_claimed'));
                $familyClaimAttitude    = trim($request->request->getString('family_claim_attitude'));
                $registeredInSeneca     = $this->parseBoolField($request->request->getString('registered_in_seneca'));

                if (empty($reportIds)) {
                    $errors['reports'] = $this->t('sanction.error.no_reports');
                }

                if ($details === '') {
                    $errors['details'] = $this->t('sanction.error.details_required');
                }

                if (!$noMeasure && empty($measureIds)) {
                    $errors['measures'] = $this->t('sanction.error.no_measures');
                }

                if ($noMeasure && $noMeasureReason === '') {
                    $errors['no_measure_reason'] = $this->t('sanction.error.no_measure_reason_required');
                }

                if (!$noMeasure && $familyClaimed === true && $familyClaimAttitude === '') {
                    $errors['family_claim_attitude'] = $this->t('sanction.error.family_claim_attitude_required');
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
                        $errors['effective_from'] = $this->t('sanction.error.effective_from_required');
                    } else {
                        $effectiveFrom = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveFromRaw) ?: null;
                        if ($effectiveFrom === null) {
                            $errors['effective_from'] = $this->t('sanction.error.effective_from_invalid');
                        }
                    }
                    if ($effectiveToRaw === '') {
                        $errors['effective_to'] = $this->t('sanction.error.effective_to_required');
                    } else {
                        $effectiveTo = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveToRaw) ?: null;
                        if ($effectiveTo === null) {
                            $errors['effective_to'] = $this->t('sanction.error.effective_to_invalid');
                        }
                    }
                }

                if (empty($errors)) {
                    $academicYear = $group->getAcademicYear();

                    $sanction = (new Sanction())
                        ->setAcademicYear($academicYear)
                        ->setStudent($student)
                        ->setGroup($group)
                        ->setRegisteredBy($user)
                        ->setDetails($details)
                        ->setCalendarLabel($calendarLabel)
                        ->setNoMeasureApplied($noMeasure)
                        ->setNoMeasureReason($noMeasure ? ($noMeasureReason ?: null) : null)
                        ->setEffectiveFrom($requiresDates ? $effectiveFrom : null)
                        ->setEffectiveTo($requiresDates ? $effectiveTo : null)
                        ->setMeasuresEffective($noMeasure ? null : $measuresEffective)
                        ->setFamilyClaimed($noMeasure ? null : $familyClaimed)
                        ->setFamilyClaimAttitude(!$noMeasure && $familyClaimed === true ? ($familyClaimAttitude ?: null) : null)
                        ->setRegisteredInSeneca($registeredInSeneca);

                    foreach ($selectedMeasures as $m) {
                        $sanction->addMeasure($m);
                    }

                    $this->em->persist($sanction);

                    // Link selected reports to this sanction
                    /** @var list<IncidentReport> $linkedReports */
                    $linkedReports = [];
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
                        $linkedReports[] = $report;
                    }

                    $this->em->flush();

                    foreach ($linkedReports as $linkedReport) {
                        $this->notifier->reportSanctioned($linkedReport, $user);
                    }

                    $tasks = $this->taskGenerator->generateFor($sanction);

                    $this->activityLog->log('sanction.created', [
                        'entityId'  => $sanction->getId()->toRfc4122(),
                        'studentId' => $student->getId()->toRfc4122(),
                        'groupId'   => $group->getId()->toRfc4122(),
                        'reportIds' => array_map(
                            static fn (IncidentReport $r): string => $r->getId()->toRfc4122(),
                            $linkedReports,
                        ),
                        'taskCount' => count($tasks),
                    ]);

                    $this->addFlash('success', $this->t('sanction.flash.created'));

                    return $this->redirectToRoute('app_sanctions_show', ['id' => $sanction->getId()->toRfc4122()]);
                }

                return $this->render('sanction/new.html.twig', [
                    'centre'                => $centre,
                    'student'               => $student,
                    'group'                 => $group,
                    'eligibleReports'       => $eligibleReports,
                    'observationsByReport'  => $this->reportObservations->findByIncidentReports($eligibleReports),
                    'communicationsByReport' => $this->communications->findByIncidentReports($eligibleReports),
                    'measuresByCategory'    => $measuresByCategory,
                    'errors'                => $errors,
                    'formData'           => [
                        'reports'               => array_values(array_filter($reportIds, 'is_string')),
                        'measureIds'            => array_values(array_filter($measureIds, 'is_string')),
                        'details'               => $details,
                        'calendarLabel'         => $calendarLabel,
                        'noMeasure'             => $noMeasure,
                        'noMeasureReason'       => $noMeasureReason,
                        'effectiveFrom'         => $effectiveFromRaw,
                        'effectiveTo'           => $effectiveToRaw,
                        'measuresEffective'     => $measuresEffective,
                        'familyClaimed'         => $familyClaimed,
                        'familyClaimAttitude'   => $familyClaimAttitude,
                        'registeredInSeneca'    => $registeredInSeneca,
                    ],
                ]);
            }

            return $this->render('sanction/new.html.twig', [
                'centre'                => $centre,
                'student'               => $student,
                'group'                 => $group,
                'eligibleReports'       => $eligibleReports,
                'observationsByReport'  => $this->reportObservations->findByIncidentReports($eligibleReports),
                'communicationsByReport' => $this->communications->findByIncidentReports($eligibleReports),
                'measuresByCategory'    => $measuresByCategory,
                'errors'                => [],
                'formData'              => [],
            ]);
        }

        // Step 1 — live-filtered student list with report stats
        return $this->render('sanction/new_select_student.html.twig', [
            'centre' => $centre,
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
            'centre'       => $centre,
            'sanction'     => $sanction,
            'history'      => $this->communications->findBySanction($sanction),
            'observations' => $this->sanctionObservations->findBySanction($sanction),
        ]);
    }

    #[Route('/{id}/pdf', name: 'app_sanctions_pdf', methods: ['GET'])]
    public function pdf(string $id): Response
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

        $reports = $sanction->getReports()->toArray();

        $placeholders = [
            'title'         => $this->translator->trans('pdf.sanction.title', [], 'admin'),
            'student_name'  => $sanction->getStudent()->getName()->full(),
            'group_name'    => $sanction->getGroup()->getName(),
            'centre_name'   => $centre->getName(),
            'academic_year' => $sanction->getAcademicYear()->getName(),
        ];

        $header = $this->pdfHeaderBuilder->build('sanction', $centre, $placeholders);
        $footer = $this->pdfHeaderBuilder->buildFooter('sanction', $centre, $placeholders);

        return $this->pdfRenderer->render(
            'pdf/sanction.html.twig',
            [
                'centre'                  => $centre,
                'sanction'                => $sanction,
                'history'                 => $this->communications->findBySanction($sanction),
                'observations'            => $this->sanctionObservations->findBySanction($sanction),
                'observationsByReport'    => $this->reportObservations->findByIncidentReports($reports),
                'communicationsByReport'  => $this->communications->findByIncidentReports($reports),
                'footerHtml'              => $footer,
            ],
            $this->translator->trans('sanction.show_title', [], 'admin')
                . ' — ' . $sanction->getStudent()->getName()->getLastName() . ', ' . $sanction->getStudent()->getName()->getFirstName(),
            sprintf('sancion-%s.pdf', substr($sanction->getId()->toRfc4122(), 0, 8)),
            header: $header,
            draftWatermark: !$sanction->isNotified() && $this->settings->getForCentre('reports.draft_watermark_enabled', $centre) === true,
        );
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

        // Full edit requires EDIT; after notification VIEW users get EDIT_FOLLOWUP (only new fields + observations)
        if (!$this->isGranted(SanctionVoter::EDIT, $sanction)
            && !$this->isGranted(SanctionVoter::EDIT_FOLLOWUP, $sanction)
        ) {
            throw $this->createAccessDeniedException();
        }
        $this->denyIfViewingPastYear($centre);

        $canEditAll = $this->isGranted(SanctionVoter::EDIT, $sanction);

        $user = $this->getUser();
        \assert($user instanceof Teacher);

        $measuresByCategory = $canEditAll
            ? $this->groupMeasuresByCategory($this->measures->findByCentreActive($centre))
            : [];

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_sanction_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $measuresEffective   = $this->parseBoolField($request->request->getString('measures_effective'));
            $familyClaimed       = $this->parseBoolField($request->request->getString('family_claimed'));
            $familyClaimAttitude = trim($request->request->getString('family_claim_attitude'));
            $registeredInSeneca  = $this->parseBoolField($request->request->getString('registered_in_seneca'));

            if (!$sanction->isNoMeasureApplied() && $familyClaimed === true && $familyClaimAttitude === '') {
                $errors['family_claim_attitude'] = $this->t('sanction.error.family_claim_attitude_required');
            }

            if ($canEditAll) {
                $previouslyLinkedIds = array_map(
                    static fn (IncidentReport $r): string => $r->getId()->toRfc4122(),
                    $sanction->getReports()->toArray(),
                );

                $reportIds       = $request->request->all('reports');
                $measureIds      = $request->request->all('measures');
                $details         = trim($request->request->getString('details'));
                $calendarLabel   = trim($request->request->getString('calendar_label')) ?: null;
                $noMeasure       = $request->request->getBoolean('no_measure_applied');
                $noMeasureReason = trim($request->request->getString('no_measure_reason'));
                $effectiveFromRaw = trim($request->request->getString('effective_from'));
                $effectiveToRaw   = trim($request->request->getString('effective_to'));

                if (empty($reportIds)) {
                    $errors['reports'] = $this->t('sanction.error.no_reports');
                }

                if ($details === '') {
                    $errors['details'] = $this->t('sanction.error.details_required');
                }

                if (!$noMeasure && empty($measureIds)) {
                    $errors['measures'] = $this->t('sanction.error.no_measures');
                }

                if ($noMeasure && $noMeasureReason === '') {
                    $errors['no_measure_reason'] = $this->t('sanction.error.no_measure_reason_required');
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
                        $errors['effective_from'] = $this->t('sanction.error.effective_from_required');
                    } else {
                        $effectiveFrom = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveFromRaw) ?: null;
                        if ($effectiveFrom === null) {
                            $errors['effective_from'] = $this->t('sanction.error.effective_from_invalid');
                        }
                    }
                    if ($effectiveToRaw === '') {
                        $errors['effective_to'] = $this->t('sanction.error.effective_to_required');
                    } else {
                        $effectiveTo = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveToRaw) ?: null;
                        if ($effectiveTo === null) {
                            $errors['effective_to'] = $this->t('sanction.error.effective_to_invalid');
                        }
                    }
                }
            }

            if (empty($errors)) {
                $before = $this->changeTracker->snapshot($sanction, self::LOGGED_SANCTION_FIELDS);

                $noMeasureForFollowup = $canEditAll ? $noMeasure : $sanction->isNoMeasureApplied();

                $sanction->setMeasuresEffective($noMeasureForFollowup ? null : $measuresEffective)
                         ->setFamilyClaimed($noMeasureForFollowup ? null : $familyClaimed)
                         ->setFamilyClaimAttitude(!$noMeasureForFollowup && $familyClaimed === true ? ($familyClaimAttitude ?: null) : null)
                         ->setRegisteredInSeneca($registeredInSeneca);

                if ($canEditAll) {
                    $sanction->setDetails($details)
                             ->setCalendarLabel($calendarLabel)
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

                    /** @var list<IncidentReport> $newlyLinkedReports */
                    $newlyLinkedReports = [];

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

                        if (!in_array($report->getId()->toRfc4122(), $previouslyLinkedIds, true)) {
                            $newlyLinkedReports[] = $report;
                        }
                    }

                    $this->em->flush();

                    foreach ($newlyLinkedReports as $newlyLinkedReport) {
                        $this->notifier->reportSanctioned($newlyLinkedReport, $user);
                    }

                    $this->taskGenerator->generateFor($sanction);

                    $currentLinkedIds = array_map(
                        static fn (IncidentReport $r): string => $r->getId()->toRfc4122(),
                        $sanction->getReports()->toArray(),
                    );
                    sort($previouslyLinkedIds);
                    sort($currentLinkedIds);
                } else {
                    $this->em->flush();
                }

                $changes = $this->changeTracker->diff($before, $sanction, self::LOGGED_SANCTION_FIELDS);

                if ($canEditAll && $previouslyLinkedIds !== $currentLinkedIds) {
                    $changes['reportIds'] = ['before' => $previouslyLinkedIds, 'after' => $currentLinkedIds];
                }

                if ($changes !== []) {
                    $this->activityLog->log('sanction.updated', [
                        'entityId' => $sanction->getId()->toRfc4122(),
                        'changes'  => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('sanction.flash.updated'));

                return $this->redirectToRoute('app_sanctions_show', ['id' => $id]);
            }
        }

        // For edit: eligible = already linked + newly eligible for this student/group
        $linked   = $sanction->getReports()->toArray();
        $linkedIds = array_map(
            static fn(\App\Entity\IncidentReport $r): string => $r->getId()->toRfc4122(),
            $linked
        );
        $availableReports = $canEditAll
            ? array_merge(
                $linked,
                array_filter(
                    $this->sanctions->findEligibleReports($sanction->getStudent(), $sanction->getGroup()),
                    static fn(\App\Entity\IncidentReport $r): bool => !in_array($r->getId()->toRfc4122(), $linkedIds, true)
                )
            )
            : $linked;

        return $this->render('sanction/edit.html.twig', [
            'centre'                => $centre,
            'sanction'              => $sanction,
            'canEditAll'            => $canEditAll,
            'availableReports'      => $availableReports,
            'observationsByReport'  => $canEditAll ? $this->reportObservations->findByIncidentReports($availableReports) : [],
            'communicationsByReport' => $canEditAll ? $this->communications->findByIncidentReports($availableReports) : [],
            'measuresByCategory'    => $measuresByCategory,
            'errors'                => $errors,
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
        $this->denyIfViewingPastYear($this->centreFor($sanction));

        if (!$this->isCsrfTokenValid('delete_sanction_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Unlink reports before removal (cascade not set for this side)
        foreach ($sanction->getReports()->toArray() as $r) {
            /** @var IncidentReport $r */
            $r->setSanction(null);
        }

        $entityId  = $sanction->getId()->toRfc4122();
        $studentId = $sanction->getStudent()->getId()->toRfc4122();

        $this->em->remove($sanction);
        $this->em->flush();

        $this->activityLog->log('sanction.deleted', [
            'entityId'  => $entityId,
            'studentId' => $studentId,
        ]);

        $this->addFlash('success', $this->t('sanction.flash.deleted'));

        return $this->redirectToRoute('app_sanctions_index');
    }

    #[Route('/{id}/tareas/refrescar', name: 'app_sanction_tasks_refresh_preview', methods: ['GET'])]
    public function refreshTasksPreview(string $id): Response
    {
        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SanctionVoter::EDIT, $sanction);
        $this->denyIfViewingPastYear($this->centreFor($sanction));

        $diff = $this->computeTaskRefreshDiff($sanction);

        return $this->render('sanction/tasks_refresh_preview.html.twig', [
            'sanction'         => $sanction,
            'toAdd'            => $diff['toAdd'],
            'toRemoveWithData' => $diff['toRemoveWithData'],
            'toRemoveEmpty'    => $diff['toRemoveEmpty'],
        ]);
    }

    #[Route('/{id}/tareas/refrescar', name: 'app_sanction_tasks_refresh_confirm', methods: ['POST'])]
    public function refreshTasksConfirm(string $id, Request $request): Response
    {
        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SanctionVoter::EDIT, $sanction);
        $this->denyIfViewingPastYear($this->centreFor($sanction));

        if (!$this->isCsrfTokenValid('refresh_sanction_tasks_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Recomputed here rather than trusting the preview that traveled in the HTML, in case
        // the group's teaching assignments or the tasks changed in the meantime.
        $diff = $this->computeTaskRefreshDiff($sanction);

        foreach ($diff['toAdd'] as $groupTeacher) {
            $this->em->persist(new SanctionTask($sanction, $groupTeacher));
        }

        foreach (array_merge($diff['toRemoveWithData'], $diff['toRemoveEmpty']) as $task) {
            $sanction->getTasks()->removeElement($task);
        }

        $added   = count($diff['toAdd']);
        $removed = count($diff['toRemoveWithData']) + count($diff['toRemoveEmpty']);

        $this->em->flush();

        if ($added > 0 || $removed > 0) {
            $this->activityLog->log('sanction_task.refreshed', [
                'entityId' => $sanction->getId()->toRfc4122(),
                'added'    => $added,
                'removed'  => $removed,
            ]);
        }

        $this->addFlash('success', $this->t('sanction_task.refresh.flash.done'));

        return $this->redirectToRoute('app_sanctions_show', ['id' => $id]);
    }

    /**
     * @return array{toAdd: list<GroupTeacher>, toRemoveWithData: list<SanctionTask>, toRemoveEmpty: list<SanctionTask>}
     */
    private function computeTaskRefreshDiff(Sanction $sanction): array
    {
        $currentGroupTeachers = $sanction->getGroup()->getTeacherAssignments()->toArray();
        $existingTasks        = $sanction->getTasks()->toArray();

        $existingGroupTeacherIds = array_map(
            static fn (SanctionTask $t): string => $t->getGroupTeacher()->getId()->toRfc4122(),
            $existingTasks,
        );
        $currentGroupTeacherIds = array_map(
            static fn (GroupTeacher $gt): string => $gt->getId()->toRfc4122(),
            $currentGroupTeachers,
        );

        $toAdd = array_values(array_filter(
            $currentGroupTeachers,
            static fn (GroupTeacher $gt): bool => !in_array($gt->getId()->toRfc4122(), $existingGroupTeacherIds, true),
        ));

        $toRemoveWithData = [];
        $toRemoveEmpty    = [];
        foreach ($existingTasks as $task) {
            if (in_array($task->getGroupTeacher()->getId()->toRfc4122(), $currentGroupTeacherIds, true)) {
                continue;
            }
            if ($task->getCompletedAt() !== null || !$task->getAttachments()->isEmpty()) {
                $toRemoveWithData[] = $task;
            } else {
                $toRemoveEmpty[] = $task;
            }
        }

        return [
            'toAdd'            => $toAdd,
            'toRemoveWithData' => $toRemoveWithData,
            'toRemoveEmpty'    => $toRemoveEmpty,
        ];
    }

    #[Route('/{id}/observaciones', name: 'app_sanctions_add_observation', methods: ['POST'])]
    public function addObservation(string $id, Request $request): Response
    {
        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SanctionVoter::VIEW, $sanction);
        $this->denyIfViewingPastYear($this->centreFor($sanction));

        if (!$this->isCsrfTokenValid('add_sanction_observation_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        \assert($user instanceof Teacher);

        $text = trim($request->request->getString('text'));

        if ($text === '') {
            $this->addFlash('error', $this->t('sanction.observation.error.text_required'));

            return $this->redirectToRoute('app_sanctions_show', ['id' => $id]);
        }

        $observation = new SanctionObservation($sanction, $user, new \DateTimeImmutable(), $text);
        $this->em->persist($observation);
        $this->em->flush();

        $this->activityLog->log('sanction_observation.created', [
            'entityId'   => $observation->getId()->toRfc4122(),
            'sanctionId' => $sanction->getId()->toRfc4122(),
        ]);

        $this->addFlash('success', $this->t('sanction.observation.flash.added'));

        return $this->redirectToRoute('app_sanctions_show', ['id' => $id]);
    }

    #[Route('/{id}/observaciones/{observationId}/editar', name: 'app_sanctions_edit_observation', methods: ['GET', 'POST'])]
    public function editObservation(string $id, string $observationId, Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $observation = $this->sanctionObservations->findById($observationId);
        if ($observation === null || $observation->getSanction() !== $sanction) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SanctionObservationVoter::EDIT, $observation);
        $this->denyIfViewingPastYear($centre);

        $canEditDate = $this->isGranted(SanctionObservationVoter::EDIT_DATE, $observation);

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_sanction_observation_' . $observationId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $text         = trim($request->request->getString('text'));
            $registeredAt = $observation->getRegisteredAt();

            if ($canEditDate) {
                $registeredAtRaw = trim($request->request->getString('registered_at'));
                $registeredAt    = null;
                if ($registeredAtRaw !== '') {
                    try {
                        $registeredAt = new \DateTimeImmutable($registeredAtRaw);
                    } catch (\Exception) {
                        $registeredAt = null;
                    }
                }
                if ($registeredAt === null) {
                    $errors['registered_at'] = $this->t('sanction.observation.error.invalid');
                }
            }

            if ($text === '') {
                $errors['text'] = $this->t('sanction.observation.error.invalid');
            }

            if (empty($errors) && $registeredAt !== null) {
                $before = $this->changeTracker->snapshot($observation, self::LOGGED_OBSERVATION_FIELDS);

                $observation->setRegisteredAt($registeredAt)->setText($text);
                $this->em->flush();

                $changes = $this->changeTracker->diff($before, $observation, self::LOGGED_OBSERVATION_FIELDS);
                if ($changes !== []) {
                    $this->activityLog->log('sanction_observation.updated', [
                        'entityId'   => $observation->getId()->toRfc4122(),
                        'sanctionId' => $sanction->getId()->toRfc4122(),
                        'changes'    => $changes,
                    ]);
                }

                $this->addFlash('success', $this->t('sanction.observation.flash.updated'));

                return $this->redirectToRoute('app_sanctions_show', ['id' => $id]);
            }
        }

        return $this->render('sanction/observation_edit.html.twig', [
            'centre'      => $centre,
            'sanction'    => $sanction,
            'observation' => $observation,
            'canEditDate' => $canEditDate,
            'errors'      => $errors,
        ]);
    }

    #[Route('/{id}/observaciones/{observationId}/eliminar', name: 'app_sanctions_delete_observation', methods: ['POST'])]
    public function deleteObservation(string $id, string $observationId, Request $request): Response
    {
        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $observation = $this->sanctionObservations->findById($observationId);
        if ($observation === null || $observation->getSanction() !== $sanction) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SanctionObservationVoter::DELETE, $observation);
        $this->denyIfViewingPastYear($this->centreFor($sanction));

        if (!$this->isCsrfTokenValid('delete_sanction_observation_' . $observationId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entityId = $observation->getId()->toRfc4122();

        $this->em->remove($observation);
        $this->em->flush();

        $this->activityLog->log('sanction_observation.deleted', [
            'entityId'   => $entityId,
            'sanctionId' => $sanction->getId()->toRfc4122(),
        ]);

        $this->addFlash('success', $this->t('sanction.observation.flash.deleted'));

        return $this->redirectToRoute('app_sanctions_show', ['id' => $id]);
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

    private function centreFor(Sanction $sanction): EducationalCentre
    {
        return $sanction->getGroup()->getAcademicYear()->getEducationalCentre();
    }

    private function denyIfViewingPastYear(EducationalCentre $centre): void
    {
        if ($this->tenantContext->isViewingNonActiveYear($centre)) {
            throw $this->createAccessDeniedException('Write operations are not allowed while viewing a non-active academic year.');
        }
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }

    private function parseBoolField(string $raw): ?bool
    {
        if ($raw === '1') {
            return true;
        }
        if ($raw === '0') {
            return false;
        }

        return null;
    }
}
