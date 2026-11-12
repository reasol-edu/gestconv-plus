<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AcademicYear;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Pagination\Paginator;
use App\Repository\DailyNoteRepository;
use App\Repository\DailyNoteTypeRepository;
use App\Repository\GroupRepository;
use App\Repository\StudentRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\DailyNoteVoter;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityLogService;
use App\Service\AppSettingsInterface;
use App\Service\EntityChangeTracker;
use App\Service\IncidentEmailNotifier;
use App\Service\QuickStudentSelection;
use App\Service\TeacherYearRoles;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/notas')]
class DailyNoteController extends AbstractController
{
    use PastYearGuardTrait;
    use TranslatorTrait;

    /** @var list<string> */
    private const LOGGED_FIELDS = ['observations', 'active'];

    /** Tope de estudiantes por envío del formulario (el mismo que la pantalla de resumen de partes creados). */
    private const MAX_STUDENTS_PER_FORM = 50;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TeacherYearRoles $roles,
        private readonly EntityManagerInterface $em,
        private readonly DailyNoteRepository $notes,
        private readonly DailyNoteTypeRepository $types,
        private readonly StudentRepository $students,
        private readonly GroupRepository $groups,
        private readonly TeacherRepository $teachers,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
        private readonly EntityChangeTracker $changeTracker,
        private readonly AppSettingsInterface $settings,
        private readonly IncidentEmailNotifier $notifier,
        private readonly QuickStudentSelection $quickSelection,
    ) {}

    #[Route('', name: 'app_daily_notes_index')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $year = $this->tenantContext->getViewYear($centre);
        $canSeeStudents = $user->isAdmin()
            || $centre->getAdmins()->contains($user)
            || ($year !== null && $this->roles->tutorsAnyGroup($user, $year));

        $tab = $request->query->getString('tab', 'notes') === 'students' && $canSeeStudents ? 'students' : 'notes';

        return $this->render('daily_note/index.html.twig', [
            'centre'         => $centre,
            'tab'            => $tab,
            'canSeeStudents' => $canSeeStudents,
            'typeId'         => trim($request->query->getString('typeId')),
        ]);
    }

    #[Route('/estado-tipos', name: 'app_daily_notes_type_status', methods: ['GET'])]
    public function typeStatus(Request $request, #[CurrentCentre] EducationalCentre $centre): JsonResponse
    {
        if (!$this->getUser() instanceof Teacher) {
            return $this->json(['types' => []]);
        }

        $year = $centre->getActiveAcademicYear();

        // Uno o varios estudiantes (`studentIds`, separados por comas; `studentId` se sigue aceptando): un tipo
        // se marca como «alcanzaría el umbral» si lo alcanzaría para cualquiera de ellos.
        $rawIds = array_filter(array_merge(
            [$request->query->getString('studentId')],
            explode(',', $request->query->getString('studentIds')),
        ));
        $students = [];
        foreach (array_slice(array_values(array_unique($rawIds)), 0, self::MAX_STUDENTS_PER_FORM) as $rawId) {
            $student = $this->students->findById($rawId);
            if ($student !== null && $this->students->belongsToCentre($student, $centre)) {
                $students[] = $student;
            }
        }

        // Una consulta por estudiante (no una por estudiante y tipo).
        $countsByStudent = [];
        if ($year !== null) {
            foreach ($students as $student) {
                $countsByStudent[] = $this->notes->countActiveByStudentGroupedByType($student, $year);
            }
        }

        $types = [];
        foreach ($this->types->findByCentreActive($centre) as $type) {
            $count = 0;
            $warn  = false;
            if ($year !== null) {
                foreach ($countsByStudent as $counts) {
                    $studentCount = $counts[$type->getId()->toRfc4122()] ?? 0;
                    $count        = max($count, $studentCount);
                    $warn         = $warn || ($type->getOccurrencesForReport() > 0 && ($studentCount + 1) >= $type->getOccurrencesForReport());
                }
            }
            $types[] = [
                'id'    => $type->getId()->toRfc4122(),
                'count' => $count,
                'warn'  => $warn,
            ];
        }

        return $this->json(['types' => $types]);
    }

    #[Route('/historial-tipo', name: 'app_daily_notes_type_history', methods: ['GET'])]
    public function typeHistory(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        if (!$this->getUser() instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $year    = $centre->getActiveAcademicYear();
        $student = $this->students->findById($request->query->getString('studentId'));
        $type    = $this->types->findById($request->query->getString('typeId'));

        if ($student === null || $type === null || $year === null || $type->getEducationalCentre() !== $centre) {
            return $this->render('daily_note/_type_history.html.twig', ['pagination' => null]);
        }

        $page       = max(1, $request->query->getInt('page', 1));
        $query      = $this->notes->createHistoryQuery($student, $type, $year);
        $pagination = Paginator::fromQuery($query, $page, $this->settings->getInt('page.size'));

        return $this->render('daily_note/_type_history.html.twig', [
            'pagination' => $pagination,
            'studentId'  => $student->getId()->toRfc4122(),
            'typeId'     => $type->getId()->toRfc4122(),
        ]);
    }

    #[Route('/nuevo', name: 'app_daily_notes_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $this->denyIfViewingPastYear($centre);

        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $activeYear = $centre->getActiveAcademicYear();
        $errors     = [];
        $formData   = ['student_pair' => '', 'type_id' => '', 'observations' => ''];
        /** @var list<array{value: string, label: string, secondary: string}> $preloadedStudents */
        $preloadedStudents = [];

        $canChooseTeacher = $this->isGranted(EducationalCentreVoter::SECTION, $centre);
        $selectedTeacher  = $activeYear !== null && $this->teachers->findByAcademicYearAndId($activeYear, $user->getId()->toRfc4122()) !== null
            ? $user
            : null;
        $registeredBy = $user;

        $studentId = trim($request->query->getString('studentId'));
        $groupId   = trim($request->query->getString('groupId'));
        if ($studentId !== '' && $groupId !== '') {
            $student = $this->students->findById($studentId);
            $group   = $this->groups->findByIdAndCentre($groupId, $centre);
            if ($student !== null && $group !== null && $student->getGroups()->contains($group)) {
                $preloadedStudents[] = [
                    'value'     => $studentId . '::' . $groupId,
                    'label'     => $student->getName()->getLastName() . ', ' . $student->getName()->getFirstName(),
                    'secondary' => $group->getName(),
                ];
            }
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_daily_note', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            // Uno o varios estudiantes («estudiante::grupo»); `student_pair` (uno solo) se sigue aceptando.
            $pairs = [];
            foreach ($request->request->all('students') as $rawPair) {
                if (is_string($rawPair) && trim($rawPair) !== '') {
                    $pairs[] = trim($rawPair);
                }
            }
            $singlePair = trim($request->request->getString('student_pair'));
            if ($singlePair !== '') {
                $pairs[] = $singlePair;
            }
            $pairs = array_values(array_unique($pairs));

            $formData['type_id']      = trim($request->request->getString('type_id'));
            $formData['observations'] = trim($request->request->getString('observations'));

            if ($canChooseTeacher) {
                $registeredByRaw = trim($request->request->getString('registered_by'));
                $chosenTeacher   = $activeYear !== null && $registeredByRaw !== ''
                    ? $this->teachers->findByAcademicYearAndId($activeYear, $registeredByRaw)
                    : null;
                if ($chosenTeacher === null) {
                    $errors['registered_by'] = $this->t('daily_note.error.invalid_teacher');
                } else {
                    $registeredBy = $chosenTeacher;
                }
            }

            /** @var list<array{0: Student, 1: Group}> $targets */
            $targets = [];
            $seen    = [];
            foreach ($pairs as $pair) {
                $parts = explode('::', $pair, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $student = $this->students->findById($parts[0]);
                $group   = $this->groups->findByIdAndCentre($parts[1], $centre);
                if ($student === null || $group === null || !$student->getGroups()->contains($group)) {
                    continue;
                }
                // Una sola nota por estudiante, aunque llegue en dos grupos.
                $key = $student->getId()->toRfc4122();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $targets[]  = [$student, $group];

                $preloadedStudents[] = [
                    'value'     => $pair,
                    'label'     => $student->getName()->getLastName() . ', ' . $student->getName()->getFirstName(),
                    'secondary' => $group->getName(),
                ];
            }
            if ($targets === []) {
                $errors['student'] = $this->t('daily_note.error.no_student');
            } elseif (count($targets) > self::MAX_STUDENTS_PER_FORM) {
                $errors['student'] = $this->translator->trans('daily_note.error.too_many_students', ['%max%' => self::MAX_STUDENTS_PER_FORM], 'admin');
            }

            $type = $formData['type_id'] !== '' ? $this->types->findById($formData['type_id']) : null;
            if ($type === null || $type->getEducationalCentre() !== $centre || !$type->isActive()) {
                $errors['type'] = $this->t('daily_note.error.no_type');
            }

            if (empty($errors) && $activeYear !== null && $type !== null) {
                // Una nota por estudiante, cada una por el mismo camino (registro de actividad y aviso de umbral).
                $ids = [];
                foreach ($targets as [$student, $group]) {
                    $ids[] = $this->createNote($student, $group, $type, $registeredBy, $formData['observations'], $activeYear)->getId()->toRfc4122();
                }

                return count($ids) === 1
                    ? $this->redirectToRoute('app_daily_notes_created', ['id' => $ids[0]])
                    : $this->redirectToRoute('app_daily_notes_created_many', ['ids' => implode(',', $ids)]);
            }
        }

        $quick = $this->quickSelection->groupsFor($user, $centre);

        return $this->render('daily_note/new.html.twig', [
            'centre'            => $centre,
            'types'             => $this->types->findByCentreActive($centre),
            'errors'            => $errors,
            'formData'          => $formData,
            'preloadedStudents' => $preloadedStudents,
            'canChooseTeacher'  => $canChooseTeacher,
            'selectedTeacher'   => $selectedTeacher,
            'quickMine'         => $quick['mine'],
            'quickOthers'       => $quick['others'],
        ]);
    }

    private function createNote(
        Student $student,
        Group $group,
        DailyNoteType $type,
        Teacher $registeredBy,
        string $observations,
        AcademicYear $year,
    ): DailyNote {
        $countBefore = $this->notes->countActiveByStudentAndType($student, $type, $year);

        $note = (new DailyNote())
            ->setAcademicYear($year)
            ->setStudent($student)
            ->setGroup($group)
            ->setType($type)
            ->setRegisteredBy($registeredBy)
            ->setObservations($observations !== '' ? $observations : null);

        $this->em->persist($note);
        $this->em->flush();

        $this->activityLog->log('daily_note.created', [
            'entityId'  => $note->getId()->toRfc4122(),
            'studentId' => $student->getId()->toRfc4122(),
            'typeId'    => $type->getId()->toRfc4122(),
        ]);

        $threshold = $type->getOccurrencesForReport();
        if ($threshold > 0 && $countBefore < $threshold && ($countBefore + 1) >= $threshold) {
            $activeNotes = $this->notes->findActiveByStudentAndType($student, $type, $year);
            $this->notifier->dailyNoteThresholdReached($type, $student, $group, $activeNotes);
        }

        return $note;
    }

    #[Route('/{id}/creada', name: 'app_daily_notes_created', methods: ['GET'])]
    public function created(string $id): Response
    {
        $note = $this->notes->findById($id);
        if ($note === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(DailyNoteVoter::VIEW, $note);

        return $this->render('daily_note/created.html.twig', ['entries' => [$this->createdEntry($note)]]);
    }

    /** Resumen tras registrar la misma nota para varios estudiantes: solo se muestran las que el docente puede ver. */
    #[Route('/creadas', name: 'app_daily_notes_created_many', methods: ['GET'])]
    public function createdMany(Request $request): Response
    {
        $ids = array_slice(array_filter(explode(',', $request->query->getString('ids'))), 0, self::MAX_STUDENTS_PER_FORM);

        $entries = [];
        foreach ($this->notes->findByIds($ids) as $note) {
            if ($this->isGranted(DailyNoteVoter::VIEW, $note)) {
                $entries[] = $this->createdEntry($note);
            }
        }
        if ($entries === []) {
            throw $this->createNotFoundException();
        }

        return $this->render('daily_note/created.html.twig', ['entries' => $entries]);
    }

    /** @return array{note: DailyNote, count: int} */
    private function createdEntry(DailyNote $note): array
    {
        return [
            'note'  => $note,
            'count' => $this->notes->countActiveByStudentAndType($note->getStudent(), $note->getType(), $note->getAcademicYear()),
        ];
    }

    #[Route('/{id}/editar', name: 'app_daily_notes_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $note = $this->notes->findById($id);
        if ($note === null) {
            throw $this->createNotFoundException();
        }

        $canManage = $this->isGranted(DailyNoteVoter::MANAGE, $note);
        if (!$canManage) {
            $this->denyAccessUnlessGranted(DailyNoteVoter::EDIT_OBSERVATIONS, $note);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_daily_note_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $before = $this->changeTracker->snapshot($note, self::LOGGED_FIELDS);

            $observations = trim($request->request->getString('observations'));
            $note->setObservations($observations !== '' ? $observations : null);

            if ($canManage) {
                $active = $request->request->getBoolean('active');
                $note->setActive($active);
            }

            $this->em->flush();

            $changes = $this->changeTracker->diff($before, $note, self::LOGGED_FIELDS);
            if ($changes !== []) {
                $this->activityLog->log('daily_note.updated', [
                    'entityId' => $note->getId()->toRfc4122(),
                    'changes'  => $changes,
                ]);
            }

            $this->addFlash('success', $this->t('daily_note.flash.updated'));

            return $this->redirectToRoute('app_students_show', ['id' => $note->getStudent()->getId()->toRfc4122()]);
        }

        return $this->render('daily_note/edit.html.twig', [
            'note'      => $note,
            'canManage' => $canManage,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_daily_notes_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $note = $this->notes->findById($id);
        if ($note === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isGranted(DailyNoteVoter::MANAGE, $note)) {
            $this->denyAccessUnlessGranted(DailyNoteVoter::DELETE_OWN, $note);
        }

        if (!$this->isCsrfTokenValid('delete_daily_note_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $studentId = $note->getStudent()->getId()->toRfc4122();

        $this->em->remove($note);
        $this->em->flush();

        $this->activityLog->log('daily_note.deleted', ['entityId' => $id]);
        $this->addFlash('success', $this->t('daily_note.flash.deleted'));

        return $this->redirectToRoute('app_students_show', ['id' => $studentId]);
    }

    #[Route('/{id}/desactivar', name: 'app_daily_notes_deactivate_one', methods: ['POST'])]
    public function deactivateOne(string $id, Request $request): Response
    {
        $note = $this->notes->findById($id);
        if ($note === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(DailyNoteVoter::DEACTIVATE, $note);

        if (!$this->isCsrfTokenValid('deactivate_daily_note_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $note->setActive(false);
        $this->em->flush();

        $this->activityLog->log('daily_note.deactivated', ['entityId' => $id]);
        $this->addFlash('success', $this->t('daily_note.flash.deactivated'));

        return $this->redirectToRoute('app_students_show', ['id' => $note->getStudent()->getId()->toRfc4122()]);
    }

    #[Route('/desactivar-tipo', name: 'app_daily_notes_deactivate_type', methods: ['POST'])]
    public function deactivateAllOfType(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $studentId = $request->request->getString('studentId');
        $typeId    = $request->request->getString('typeId');

        $student = $this->students->findById($studentId);
        $type    = $this->types->findById($typeId);
        $year    = $centre->getActiveAcademicYear();

        if ($student === null || $type === null || $year === null || !$this->students->belongsToCentre($student, $centre)) {
            throw $this->createNotFoundException();
        }

        $isTutor = false;
        foreach ($student->getGroups() as $group) {
            if ($group->getAcademicYear() === $year && $group->getTutors()->contains($user)) {
                $isTutor = true;
                break;
            }
        }

        if (!$user->isAdmin() && !$centre->getAdmins()->contains($user) && !$isTutor) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('deactivate_daily_note_type_' . $studentId . '_' . $typeId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $query = $this->notes->createHistoryQuery($student, $type, $year);
        /** @var list<DailyNote> $notesList */
        $notesList = $query->getResult();
        foreach ($notesList as $note) {
            if ($note->isActive()) {
                $note->setActive(false);
            }
        }
        $this->em->flush();

        $this->activityLog->log('daily_note.deactivated_all_of_type', [
            'studentId' => $studentId,
            'typeId'    => $typeId,
        ]);
        $this->addFlash('success', $this->t('daily_note.flash.deactivated_all'));

        return $this->redirectToRoute('app_daily_notes_index', ['tab' => 'students', 'typeId' => $typeId]);
    }
}
