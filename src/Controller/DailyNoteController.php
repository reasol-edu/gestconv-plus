<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\DailyNote;
use App\Entity\EducationalCentre;
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

    public function __construct(
        private readonly TenantContext $tenantContext,
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
            || ($year !== null && $this->groups->hasTutoredGroupsInYear($centre, $user, $year));

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

        $year    = $centre->getActiveAcademicYear();
        $student = $this->students->findById($request->query->getString('studentId'));

        $types = [];
        foreach ($this->types->findByCentreActive($centre) as $type) {
            $count   = $student !== null && $year !== null
                ? $this->notes->countActiveByStudentAndType($student, $type, $year)
                : 0;
            $types[] = [
                'id'    => $type->getId()->toRfc4122(),
                'count' => $count,
                'warn'  => $type->getOccurrencesForReport() > 0 && ($count + 1) >= $type->getOccurrencesForReport(),
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

        $canChooseTeacher = $this->isGranted(EducationalCentreVoter::SECTION, $centre);
        $selectedTeacher  = $activeYear !== null && $this->teachers->findByAcademicYearAndId($activeYear, $user->getId()->toRfc4122()) !== null
            ? $user
            : null;
        $registeredBy = $user;

        $preloadedStudent = null;
        $studentId        = trim($request->query->getString('studentId'));
        $groupId          = trim($request->query->getString('groupId'));
        if ($studentId !== '' && $groupId !== '') {
            $student = $this->students->findById($studentId);
            $group   = $this->groups->findByIdAndCentre($groupId, $centre);
            if ($student !== null && $group !== null && $student->getGroups()->contains($group)) {
                $preloadedStudent = [
                    'value'     => $studentId . '::' . $groupId,
                    'label'     => $student->getName()->getLastName() . ', ' . $student->getName()->getFirstName(),
                    'secondary' => $group->getName(),
                ];
                $formData['student_pair'] = $studentId . '::' . $groupId;
            }
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_daily_note', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $formData['student_pair'] = trim($request->request->getString('student_pair'));
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

            $student = null;
            $group   = null;
            $parts   = explode('::', $formData['student_pair'], 2);
            if (count($parts) === 2) {
                $student = $this->students->findById($parts[0]);
                $group   = $this->groups->findByIdAndCentre($parts[1], $centre);
                if ($student === null || $group === null || !$student->getGroups()->contains($group)) {
                    $student = null;
                    $group   = null;
                }
            }
            if ($student === null) {
                $errors['student'] = $this->t('daily_note.error.no_student');
            }

            $type = $formData['type_id'] !== '' ? $this->types->findById($formData['type_id']) : null;
            if ($type === null || $type->getEducationalCentre() !== $centre || !$type->isActive()) {
                $errors['type'] = $this->t('daily_note.error.no_type');
            }

            // $group nunca es null si $student no lo es: se reinician juntos más arriba.
            if (empty($errors) && $activeYear !== null && $student !== null && $type !== null) {
                $countBefore = $this->notes->countActiveByStudentAndType($student, $type, $activeYear);

                $note = (new DailyNote())
                    ->setAcademicYear($activeYear)
                    ->setStudent($student)
                    ->setGroup($group)
                    ->setType($type)
                    ->setRegisteredBy($registeredBy)
                    ->setObservations($formData['observations'] !== '' ? $formData['observations'] : null);

                $this->em->persist($note);
                $this->em->flush();

                $this->activityLog->log('daily_note.created', [
                    'entityId'  => $note->getId()->toRfc4122(),
                    'studentId' => $student->getId()->toRfc4122(),
                    'typeId'    => $type->getId()->toRfc4122(),
                ]);

                $threshold = $type->getOccurrencesForReport();
                if ($threshold > 0 && $countBefore < $threshold && ($countBefore + 1) >= $threshold) {
                    $activeNotes = $this->notes->findActiveByStudentAndType($student, $type, $activeYear);
                    $this->notifier->dailyNoteThresholdReached($type, $student, $group, $activeNotes);
                }

                return $this->redirectToRoute('app_daily_notes_created', ['id' => $note->getId()->toRfc4122()]);
            }
        }

        return $this->render('daily_note/new.html.twig', [
            'centre'           => $centre,
            'types'            => $this->types->findByCentreActive($centre),
            'errors'           => $errors,
            'formData'         => $formData,
            'preloadedStudent' => $preloadedStudent,
            'canChooseTeacher' => $canChooseTeacher,
            'selectedTeacher'  => $selectedTeacher,
        ]);
    }

    #[Route('/{id}/creada', name: 'app_daily_notes_created', methods: ['GET'])]
    public function created(string $id): Response
    {
        $note = $this->notes->findById($id);
        if ($note === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(DailyNoteVoter::VIEW, $note);

        $year  = $note->getAcademicYear();
        $type  = $note->getType();
        $count = $this->notes->countActiveByStudentAndType($note->getStudent(), $type, $year);

        return $this->render('daily_note/created.html.twig', [
            'note'  => $note,
            'count' => $count,
        ]);
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
