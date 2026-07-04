<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\IncidentReport;
use App\Entity\Teacher;
use App\Entity\TasksCompletionStatus;
use App\Repository\CommunicationRepository;
use App\Repository\GroupRepository;
use App\Repository\IncidentBehaviorRepository;
use App\Repository\IncidentReportRepository;
use App\Repository\StudentRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Security\Voter\IncidentReportVoter;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/partes')]
class IncidentReportController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly IncidentReportRepository $reports,
        private readonly IncidentBehaviorRepository $behaviors,
        private readonly GroupRepository $groups,
        private readonly StudentRepository $students,
        private readonly TeacherRepository $teachers,
        private readonly CommunicationRepository $communications,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_incidents_index')]
    public function index(): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('incident/index.html.twig', [
            'centre' => $centre,
        ]);
    }

    #[Route('/buscar-alumnos', name: 'app_incidents_search_students', methods: ['GET'])]
    public function searchStudentGroups(Request $request): JsonResponse
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null || !$this->getUser() instanceof Teacher) {
            return $this->json([]);
        }

        $q = trim($request->query->getString('q'));
        if (strlen($q) < 2) {
            return $this->json([]);
        }

        $pairs   = $this->reports->searchStudentGroupPairs($centre, $q, 20);
        $results = [];

        foreach ($pairs as $pair) {
            $student    = $pair['student'];
            $group      = $pair['group'];
            $results[] = [
                'value'     => $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122(),
                'label'     => $student->getName()->getLastName() . ', ' . $student->getName()->getFirstName(),
                'secondary' => $group->getName(),
            ];
        }

        return $this->json($results);
    }

    #[Route('/nuevo', name: 'app_incidents_new', methods: ['GET', 'POST'])]
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

        $behaviorsByCategory = $this->groupBehaviorsByCategory($this->behaviors->findByCentreActive($centre));
        $errors              = [];
        $formData            = [];
        $preloadedStudents   = [];
        $canChooseTeacher    = $this->isGranted(EducationalCentreVoter::SECTION, $centre);
        $registeredBy        = $user;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_incident', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            if ($canChooseTeacher) {
                $registeredByRaw = trim($request->request->getString('registered_by'));
                if ($registeredByRaw !== '') {
                    $activeYear      = $centre->getActiveAcademicYear();
                    $selectedTeacher = $activeYear !== null ? $this->teachers->findByAcademicYearAndId($activeYear, $registeredByRaw) : null;
                    if ($selectedTeacher === null) {
                        $errors['registered_by'] = $this->t('incident.error.invalid_teacher');
                    } else {
                        $registeredBy = $selectedTeacher;
                    }
                }
            }

            $studentPairs      = $request->request->all('students');
            $behaviorIds       = $request->request->all('behaviors');
            $occurredAtRaw     = trim($request->request->getString('occurred_at'));
            $description       = trim($request->request->getString('description'));
            $expelled          = $request->request->getBoolean('expelled_from_class');
            $assignedTasks     = trim($request->request->getString('assigned_tasks')) ?: null;
            $tasksCompletedRaw = $request->request->getString('tasks_completed');

            if (empty($studentPairs)) {
                $errors['students'] = $this->t('incident.error.no_students');
            }

            if (empty($behaviorIds)) {
                $errors['behaviors'] = $this->t('incident.error.no_behaviors');
            }

            if ($description === '') {
                $errors['description'] = $this->t('incident.error.description_required');
            }

            $occurredAt = null;
            if ($occurredAtRaw !== '') {
                try {
                    $occurredAt = new \DateTimeImmutable($occurredAtRaw);
                } catch (\Exception) {
                    $errors['occurred_at'] = $this->t('incident.field.occurred_at') . ' inválida.';
                }
            } else {
                $occurredAt = new \DateTimeImmutable();
            }

            /** @var list<\App\Entity\IncidentBehavior> $selectedBehaviors */
            $selectedBehaviors = [];
            foreach ($behaviorIds as $bid) {
                if (!is_string($bid)) {
                    continue;
                }
                $b = $this->behaviors->findById($bid);
                if ($b !== null && $b->getEducationalCentre() === $centre) {
                    $selectedBehaviors[] = $b;
                }
            }

            if (empty($errors) && $occurredAt !== null) {
                $tasksCompleted = null;
                if ($expelled && $tasksCompletedRaw !== '') {
                    $tasksCompleted = TasksCompletionStatus::tryFrom($tasksCompletedRaw);
                }

                /** @var array<string, int> $nextNumbers next number per academic-year ID */
                $nextNumbers = [];

                /** @var list<IncidentReport> $createdReports */
                $createdReports = [];

                foreach ($studentPairs as $pair) {
                    if (!is_string($pair)) {
                        continue;
                    }
                    $parts = explode('::', $pair, 2);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    [$studentId, $groupId] = $parts;

                    $student = $this->students->findById($studentId);
                    $group   = $this->groups->findByIdAndCentre($groupId, $centre);

                    if ($student === null || $group === null) {
                        continue;
                    }

                    $academicYear = $group->getProgrammeYear()->getProgramme()->getAcademicYear();
                    $yearKey      = $academicYear->getId()->toRfc4122();

                    if (!array_key_exists($yearKey, $nextNumbers)) {
                        $nextNumbers[$yearKey] = $this->reports->nextNumberForYear($academicYear);
                    } else {
                        $nextNumbers[$yearKey]++;
                    }

                    $report = new IncidentReport();
                    $report->setAcademicYear($academicYear)
                           ->setNumber($nextNumbers[$yearKey])
                           ->setStudent($student)
                           ->setGroup($group)
                           ->setRegisteredBy($registeredBy)
                           ->setOccurredAt($occurredAt)
                           ->setDescription($description)
                           ->setExpelledFromClass($expelled)
                           ->setAssignedTasks($expelled ? $assignedTasks : null)
                           ->setTasksCompleted($expelled ? $tasksCompleted : null);

                    foreach ($selectedBehaviors as $beh) {
                        $report->addBehavior($beh);
                    }

                    $this->em->persist($report);
                    $createdReports[] = $report;
                }

                $this->em->flush();

                if ($createdReports === []) {
                    $this->addFlash('error', $this->t('incident.error.no_students'));

                    return $this->redirectToRoute('app_incidents_new');
                }

                return $this->redirectToRoute('app_incidents_created', [
                    'ids' => implode(',', array_map(
                        static fn (IncidentReport $r): string => $r->getId()->toRfc4122(),
                        $createdReports,
                    )),
                ]);
            }

            // Validation failed — preserve submitted values for re-render
            $formData = [
                'students'       => $studentPairs,
                'behaviorIds'    => array_values(array_filter($behaviorIds, 'is_string')),
                'occurredAt'     => $occurredAtRaw,
                'description'    => $description,
                'expelled'       => $expelled,
                'assignedTasks'  => $assignedTasks ?? '',
                'tasksCompleted' => $tasksCompletedRaw !== '' ? $tasksCompletedRaw : 'unknown',
            ];

            foreach ($studentPairs as $pair) {
                if (!is_string($pair)) {
                    continue;
                }
                $parts = explode('::', $pair, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                [$studentId, $groupId] = $parts;
                $student = $this->students->findById($studentId);
                $group   = $this->groups->findByIdAndCentre($groupId, $centre);
                if ($student !== null && $group !== null) {
                    $preloadedStudents[] = [
                        'value'     => $pair,
                        'label'     => $student->getName()->getLastName() . ', ' . $student->getName()->getFirstName(),
                        'secondary' => $group->getName(),
                    ];
                }
            }
        } else {
            $studentId = trim($request->query->getString('studentId'));
            $groupId   = trim($request->query->getString('groupId'));
            if ($studentId !== '' && $groupId !== '') {
                $student = $this->students->findById($studentId);
                $group   = $this->groups->findByIdAndCentre($groupId, $centre);
                if ($student !== null && $group !== null && $student->getGroups()->contains($group)) {
                    $preloadedStudents[] = [
                        'value'     => $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122(),
                        'label'     => $student->getName()->getLastName() . ', ' . $student->getName()->getFirstName(),
                        'secondary' => $group->getName(),
                    ];
                }
            }
        }

        $availableGroups = $this->groups->findByActiveYearOfCentreOrderedByName($centre);

        return $this->render('incident/new.html.twig', [
            'centre'              => $centre,
            'behaviorsByCategory' => $behaviorsByCategory,
            'availableGroups'     => $availableGroups,
            'errors'              => $errors,
            'formData'            => $formData,
            'preloadedStudents'   => $preloadedStudents,
            'canChooseTeacher'    => $canChooseTeacher,
            'selectedTeacher'     => $registeredBy,
        ]);
    }

    #[Route('/creados', name: 'app_incidents_created', methods: ['GET'])]
    public function created(Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $ids = array_slice(
            array_values(array_filter(explode(',', $request->query->getString('ids')))),
            0,
            50,
        );

        $createdReports = [];
        foreach ($ids as $id) {
            $report = $this->reports->findById($id);
            if ($report !== null && $this->isGranted(IncidentReportVoter::VIEW, $report)) {
                $createdReports[] = $report;
            }
        }

        if ($createdReports === []) {
            return $this->redirectToRoute('app_incidents_index');
        }

        return $this->render('incident/created.html.twig', [
            'centre'  => $centre,
            'reports' => $createdReports,
        ]);
    }

    #[Route('/{id}', name: 'app_incidents_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $report = $this->reports->findById($id);
        if ($report === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(IncidentReportVoter::VIEW, $report);

        return $this->render('incident/show.html.twig', [
            'centre'  => $centre,
            'report'  => $report,
            'history' => $this->communications->findByIncidentReport($report),
        ]);
    }

    #[Route('/{id}/editar', name: 'app_incidents_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $report = $this->reports->findById($id);
        if ($report === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(IncidentReportVoter::EDIT, $report);

        $behaviorsByCategory = $this->groupBehaviorsByCategory($this->behaviors->findByCentreActive($centre));
        $errors              = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_incident_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $behaviorIds       = $request->request->all('behaviors');
            $occurredAtRaw     = trim($request->request->getString('occurred_at'));
            $description       = trim($request->request->getString('description'));
            $expelled          = $request->request->getBoolean('expelled_from_class');
            $assignedTasks     = trim($request->request->getString('assigned_tasks')) ?: null;
            $tasksCompletedRaw = $request->request->getString('tasks_completed');
            $prescribedAtRaw   = $this->isGranted(IncidentReportVoter::PRESCRIBE, $report)
                ? trim($request->request->getString('prescribed_at'))
                : null;

            $canReassign  = $this->isGranted(IncidentReportVoter::REASSIGN, $report);
            $registeredBy = $report->getRegisteredBy();
            $student      = $report->getStudent();
            $group        = $report->getGroup();

            if ($canReassign) {
                $registeredByRaw = trim($request->request->getString('registered_by'));
                $registeredBy    = $this->teachers->findByAcademicYearAndId($report->getAcademicYear(), $registeredByRaw);
                if ($registeredBy === null) {
                    $errors['registered_by'] = $this->t('incident.error.invalid_teacher');
                }

                $studentGroupParts = explode('::', trim($request->request->getString('student_group')), 2);
                $student           = null;
                $group             = null;
                if (count($studentGroupParts) === 2) {
                    [$studentId, $groupId] = $studentGroupParts;
                    $student     = $this->students->findById($studentId);
                    $groupResult = $this->groups->find($groupId);
                    $group       = $groupResult instanceof \App\Entity\Group ? $groupResult : null;
                }
                if ($student === null || $group === null
                    || $group->getProgrammeYear()->getProgramme()->getAcademicYear() !== $report->getAcademicYear()
                ) {
                    $errors['student_group'] = $this->t('incident.error.invalid_student');
                    $student  = null;
                    $group    = null;
                }
            }

            if (empty($behaviorIds)) {
                $errors['behaviors'] = $this->t('incident.error.no_behaviors');
            }

            if ($description === '') {
                $errors['description'] = $this->t('incident.error.description_required');
            }

            $occurredAt = null;
            if ($occurredAtRaw !== '') {
                try {
                    $occurredAt = new \DateTimeImmutable($occurredAtRaw);
                } catch (\Exception) {
                    $errors['occurred_at'] = $this->t('incident.field.occurred_at') . ' inválida.';
                }
            }

            if (empty($errors)) {
                if ($occurredAt !== null) {
                    $report->setOccurredAt($occurredAt);
                }
                if ($canReassign && $registeredBy !== null && $student !== null && $group !== null) {
                    $report->setRegisteredBy($registeredBy)
                           ->setStudent($student)
                           ->setGroup($group);
                }
                $report->setDescription($description)
                       ->setExpelledFromClass($expelled)
                       ->setAssignedTasks($expelled ? $assignedTasks : null);

                $tasksCompleted = null;
                if ($expelled && $tasksCompletedRaw !== '') {
                    $tasksCompleted = TasksCompletionStatus::tryFrom($tasksCompletedRaw);
                }
                $report->setTasksCompleted($expelled ? $tasksCompleted : null);

                // Replace behaviors
                foreach ($report->getBehaviors()->toArray() as $b) {
                    $report->removeBehavior($b);
                }
                foreach ($behaviorIds as $bid) {
                    if (!is_string($bid)) {
                        continue;
                    }
                    $b = $this->behaviors->findById($bid);
                    if ($b !== null && $b->getEducationalCentre() === $centre) {
                        $report->addBehavior($b);
                    }
                }

                if ($prescribedAtRaw !== null) {
                    $prescribedAt = $prescribedAtRaw === ''
                        ? null
                        : (\DateTimeImmutable::createFromFormat('Y-m-d', $prescribedAtRaw) ?: null);
                    $report->setPrescribedAt($prescribedAt);
                }

                $this->em->flush();

                $this->addFlash('success', $this->t('incident.flash.updated'));

                return $this->redirectToRoute('app_incidents_show', ['id' => $id]);
            }
        }

        return $this->render('incident/edit.html.twig', [
            'centre'              => $centre,
            'report'              => $report,
            'behaviorsByCategory' => $behaviorsByCategory,
            'errors'              => $errors,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_incidents_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $report = $this->reports->findById($id);
        if ($report === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(IncidentReportVoter::DELETE, $report);

        if (!$this->isCsrfTokenValid('delete_incident_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->em->remove($report);
        $this->em->flush();

        $this->addFlash('success', $this->t('incident.flash.deleted'));

        return $this->redirectToRoute('app_incidents_index');
    }

    /**
     * Groups a flat list of behaviors (ordered by category.position, behavior.position)
     * into an array of ['category' => ..., 'behaviors' => [...]] entries.
     *
     * @param list<\App\Entity\IncidentBehavior> $behaviors
     * @return list<array{category: \App\Entity\IncidentBehaviorCategory, behaviors: list<\App\Entity\IncidentBehavior>}>
     */
    private function groupBehaviorsByCategory(array $behaviors): array
    {
        $groups = [];
        foreach ($behaviors as $beh) {
            $catId = $beh->getCategory()->getId()->toRfc4122();
            if (!isset($groups[$catId])) {
                $groups[$catId] = ['category' => $beh->getCategory(), 'behaviors' => []];
            }
            $groups[$catId]['behaviors'][] = $beh;
        }

        return array_values($groups);
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }
}
