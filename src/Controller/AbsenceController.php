<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Activity;
use App\Entity\ActivityAttachment;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\AbsenceRepository;
use App\Repository\ActivityAttachmentRepository;
use App\Repository\ActivityRepository;
use App\Repository\GroupTeacherRepository;
use App\Repository\TeacherRepository;
use App\Repository\TimeSlotRepository;
use App\Security\Voter\AbsenceVoter;
use App\Service\ActivityLogService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/ausencias')]
class AbsenceController extends AbstractController
{
    private const MAX_ATTACHMENT_SIZE = 10 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_ATTACHMENT_MIME_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'text/plain',
        'application/zip',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly AbsenceRepository $absences,
        private readonly ActivityRepository $activities,
        private readonly ActivityAttachmentRepository $attachmentRepository,
        private readonly GroupTeacherRepository $groupTeachers,
        private readonly TeacherRepository $teachers,
        private readonly TimeSlotRepository $timeSlots,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
    ) {}

    #[Route('', name: 'app_absences_index')]
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

        $year          = $this->tenantContext->getViewYear($centre);
        $isAdmin       = $user->isAdmin() || $centre->getAdmins()->contains($user);
        $belongsToYear = $year !== null && $year->getTeachers()->contains($user);

        if (!$isAdmin && !$belongsToYear) {
            throw $this->createAccessDeniedException();
        }

        $requestedTab = $request->query->getString('tab');
        $tab          = match (true) {
            $requestedTab === 'centre' && $isAdmin       => 'centre',
            $requestedTab === 'mine' && $belongsToYear    => 'mine',
            $belongsToYear                                => 'mine',
            default                                        => 'centre',
        };

        $absences = $tab === 'mine' && $year !== null
            ? $this->absences->findByTeacherAndYearOrderedByStartDate($user, $year)
            : [];

        return $this->render('absence/index.html.twig', [
            'centre'        => $centre,
            'year'          => $year,
            'tab'           => $tab,
            'isAdmin'       => $isAdmin,
            'belongsToYear' => $belongsToYear,
            'absences'      => $absences,
        ]);
    }

    #[Route('/nuevo', name: 'app_absences_new', methods: ['GET', 'POST'])]
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

        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            $this->addFlash('error', $this->t('absence.no_active_year'));

            return $this->redirectToRoute('app_absences_index');
        }

        $isAdmin = $user->isAdmin() || $centre->getAdmins()->contains($user);

        $errors           = [];
        $formData         = ['start_date' => '', 'end_date' => '', 'teacher_id' => $isAdmin ? '' : $user->getId()->toRfc4122()];
        $selectedTeacher  = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_absence', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $startDateRaw = trim($request->request->getString('start_date'));
            $endDateRaw   = trim($request->request->getString('end_date'));
            $teacherIdRaw = $isAdmin ? trim($request->request->getString('teacher_id')) : $user->getId()->toRfc4122();
            $formData     = ['start_date' => $startDateRaw, 'end_date' => $endDateRaw, 'teacher_id' => $teacherIdRaw];

            $startDate = self::parseDate($startDateRaw);
            $endDate   = self::parseDate($endDateRaw);

            $targetTeacher = $isAdmin
                ? ($teacherIdRaw !== '' ? $this->teachers->findByAcademicYearAndId($year, $teacherIdRaw) : null)
                : $user;
            $selectedTeacher = $targetTeacher;

            if ($startDate === null) {
                $errors['start_date'] = $this->t('absence.error.start_date_required');
            }
            if ($endDate === null) {
                $errors['end_date'] = $this->t('absence.error.end_date_required');
            }
            if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
                $errors['end_date'] = $this->t('absence.error.end_before_start');
            }
            if ($targetTeacher === null) {
                $errors['teacher_id'] = $this->t('absence.error.teacher_required');
            }

            if (empty($errors) && $endDate instanceof \DateTimeImmutable && $targetTeacher !== null) {
                $absence = new Absence();
                $absence->setTeacher($targetTeacher)
                        ->setAcademicYear($year)
                        ->setStartDate($startDate)
                        ->setEndDate($endDate);

                $this->em->persist($absence);
                $this->em->flush();

                $this->activityLog->log('absence.created', [
                    'entityId'  => $absence->getId()->toRfc4122(),
                    'teacherId' => $targetTeacher->getId()->toRfc4122(),
                ]);

                $this->addFlash('success', $this->t('absence.flash.created'));

                return $this->redirectToRoute('app_absences_show', ['id' => $absence->getId()->toRfc4122()]);
            }
        }

        return $this->render('absence/new.html.twig', [
            'centre'          => $centre,
            'year'            => $year,
            'isAdmin'         => $isAdmin,
            'errors'          => $errors,
            'formData'        => $formData,
            'selectedTeacher' => $selectedTeacher,
        ]);
    }

    #[Route('/{id}', name: 'app_absences_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $absence = $this->absences->findById($id);
        if ($absence === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(AbsenceVoter::VIEW, $absence);

        return $this->render('absence/show.html.twig', [
            'centre'  => $centre,
            'absence' => $absence,
        ]);
    }

    #[Route('/{id}/editar', name: 'app_absences_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $absence = $this->absences->findById($id);
        if ($absence === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(AbsenceVoter::EDIT, $absence);
        $this->denyIfViewingPastYear($centre);

        $errors   = [];
        $formData = [
            'start_date' => $absence->getStartDate()->format('Y-m-d'),
            'end_date'   => $absence->getEndDate()->format('Y-m-d'),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_absence_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $startDateRaw = trim($request->request->getString('start_date'));
            $endDateRaw   = trim($request->request->getString('end_date'));
            $formData     = ['start_date' => $startDateRaw, 'end_date' => $endDateRaw];

            $startDate = self::parseDate($startDateRaw);
            $endDate   = self::parseDate($endDateRaw);

            if ($startDate === null) {
                $errors['start_date'] = $this->t('absence.error.start_date_required');
            }
            if ($endDate === null) {
                $errors['end_date'] = $this->t('absence.error.end_date_required');
            }
            if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
                $errors['end_date'] = $this->t('absence.error.end_before_start');
            }

            // The date range may no longer cover every existing activity; block the narrowing in that case.
            if (empty($errors)) {
                foreach ($absence->getActivities() as $activity) {
                    if ($activity->getDate() < $startDate || $activity->getDate() > $endDate) {
                        $errors['end_date'] = $this->t('absence.error.range_excludes_activities');
                        break;
                    }
                }
            }

            if (empty($errors) && $startDate instanceof \DateTimeImmutable && $endDate instanceof \DateTimeImmutable) {
                $absence->setStartDate($startDate)->setEndDate($endDate);
                $this->em->flush();

                $this->activityLog->log('absence.updated', [
                    'entityId' => $absence->getId()->toRfc4122(),
                ]);

                $this->addFlash('success', $this->t('absence.flash.updated'));

                return $this->redirectToRoute('app_absences_show', ['id' => $id]);
            }
        }

        return $this->render('absence/edit.html.twig', [
            'centre'   => $centre,
            'absence'  => $absence,
            'errors'   => $errors,
            'formData' => $formData,
        ]);
    }

    #[Route('/{id}/eliminar', name: 'app_absences_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $absence = $this->absences->findById($id);
        if ($absence === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(AbsenceVoter::DELETE, $absence);
        $this->denyIfViewingPastYear($this->centreFor($absence));

        if (!$this->isCsrfTokenValid('delete_absence_' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entityId = $absence->getId()->toRfc4122();

        $this->em->remove($absence);
        $this->em->flush();

        $this->activityLog->log('absence.deleted', ['entityId' => $entityId]);

        $this->addFlash('success', $this->t('absence.flash.deleted'));

        return $this->redirectToRoute('app_absences_index');
    }

    #[Route('/{id}/actividades/nueva', name: 'app_absences_activity_new', methods: ['GET', 'POST'])]
    public function activityNew(string $id, Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $absence = $this->absences->findById($id);
        if ($absence === null) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(AbsenceVoter::EDIT, $absence);
        $this->denyIfViewingPastYear($centre);

        $owner = $absence->getTeacher();

        $year               = $absence->getAcademicYear();
        $availableSubjects  = $this->groupTeachers->findByTeacherAndAcademicYearOrdered($owner, $year);
        $availableTimeSlots = $this->timeSlots->findByAcademicYearOrdered($year);

        $errors   = [];
        $formData = ['date' => $absence->getStartDate()->format('Y-m-d'), 'time_slot_id' => '', 'description' => '', 'subject_ids' => []];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('new_activity_' . $id, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $result   = $this->parseActivityFields($request, $absence, $owner, $year);
            $errors   = $result['errors'];
            $formData = $result['formData'];

            $activity       = new Activity();
            $newAttachments = $this->processUploadedAttachments($activity, $request, $errors);

            if (empty($errors) && $result['date'] !== null && $result['timeSlot'] !== null) {
                $activity->setAbsence($absence)
                         ->setDate($result['date'])
                         ->setTimeSlot($result['timeSlot'])
                         ->setDescription($result['description']);

                foreach ($result['subjects'] as $subject) {
                    $activity->addSubject($subject);
                }
                foreach ($newAttachments as $attachment) {
                    $activity->addAttachment($attachment);
                }

                $this->em->persist($activity);
                $this->em->flush();

                $this->activityLog->log('activity.created', [
                    'entityId'  => $activity->getId()->toRfc4122(),
                    'absenceId' => $absence->getId()->toRfc4122(),
                ]);

                $this->addFlash('success', $this->t('activity.flash.created'));

                return $this->redirectToRoute('app_absences_show', ['id' => $id]);
            }
        }

        return $this->render('absence/activity_new.html.twig', [
            'centre'             => $centre,
            'absence'            => $absence,
            'availableSubjects'  => $availableSubjects,
            'availableTimeSlots' => $availableTimeSlots,
            'errors'             => $errors,
            'formData'           => $formData,
        ]);
    }

    #[Route('/{id}/actividades/{activityId}/editar', name: 'app_absences_activity_edit', methods: ['GET', 'POST'])]
    public function activityEdit(string $id, string $activityId, Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $absence = $this->absences->findById($id);
        if ($absence === null) {
            throw $this->createNotFoundException();
        }

        $activity = $this->activities->findById($activityId);
        if ($activity === null || $activity->getAbsence() !== $absence) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(AbsenceVoter::EDIT, $absence);
        $this->denyIfViewingPastYear($centre);

        $owner = $absence->getTeacher();

        $year               = $absence->getAcademicYear();
        $availableSubjects  = $this->groupTeachers->findByTeacherAndAcademicYearOrdered($owner, $year);
        $availableTimeSlots = $this->timeSlots->findByAcademicYearOrdered($year);

        $errors   = [];
        $formData = [
            'date'         => $activity->getDate()->format('Y-m-d'),
            'time_slot_id' => $activity->getTimeSlot()->getId()->toRfc4122(),
            'description'  => $activity->getDescription(),
            'subject_ids'  => array_map(
                static fn ($subject) => $subject->getId()->toRfc4122(),
                $activity->getSubjects()->toArray(),
            ),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_activity_' . $activityId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $result   = $this->parseActivityFields($request, $absence, $owner, $year);
            $errors   = $result['errors'];
            $formData = $result['formData'];

            $newAttachments = $this->processUploadedAttachments($activity, $request, $errors);

            /** @var list<string> $removeIds */
            $removeIds = array_values(array_filter($request->request->all('remove_attachments'), 'is_string'));

            if (empty($errors) && $result['date'] !== null && $result['timeSlot'] !== null) {
                $activity->setDate($result['date'])
                         ->setTimeSlot($result['timeSlot'])
                         ->setDescription($result['description']);

                foreach ($activity->getSubjects()->toArray() as $subject) {
                    $activity->removeSubject($subject);
                }
                foreach ($result['subjects'] as $subject) {
                    $activity->addSubject($subject);
                }

                foreach ($activity->getAttachments()->toArray() as $attachment) {
                    if (in_array($attachment->getId()->toRfc4122(), $removeIds, true)) {
                        $activity->removeAttachment($attachment);
                        $this->em->remove($attachment);
                    }
                }
                foreach ($newAttachments as $attachment) {
                    $activity->addAttachment($attachment);
                }

                $this->em->flush();

                $this->activityLog->log('activity.updated', [
                    'entityId'  => $activity->getId()->toRfc4122(),
                    'absenceId' => $absence->getId()->toRfc4122(),
                ]);

                $this->addFlash('success', $this->t('activity.flash.updated'));

                return $this->redirectToRoute('app_absences_show', ['id' => $id]);
            }
        }

        return $this->render('absence/activity_edit.html.twig', [
            'centre'             => $centre,
            'absence'            => $absence,
            'activity'           => $activity,
            'availableSubjects'  => $availableSubjects,
            'availableTimeSlots' => $availableTimeSlots,
            'errors'             => $errors,
            'formData'           => $formData,
        ]);
    }

    #[Route('/{id}/actividades/{activityId}/eliminar', name: 'app_absences_activity_delete', methods: ['POST'])]
    public function activityDelete(string $id, string $activityId, Request $request): Response
    {
        $absence = $this->absences->findById($id);
        if ($absence === null) {
            throw $this->createNotFoundException();
        }

        $activity = $this->activities->findById($activityId);
        if ($activity === null || $activity->getAbsence() !== $absence) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(AbsenceVoter::EDIT, $absence);
        $this->denyIfViewingPastYear($this->centreFor($absence));

        if (!$this->isCsrfTokenValid('delete_activity_' . $activityId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entityId = $activity->getId()->toRfc4122();

        $this->em->remove($activity);
        $this->em->flush();

        $this->activityLog->log('activity.deleted', [
            'entityId'  => $entityId,
            'absenceId' => $absence->getId()->toRfc4122(),
        ]);

        $this->addFlash('success', $this->t('activity.flash.deleted'));

        return $this->redirectToRoute('app_absences_show', ['id' => $id]);
    }

    #[Route('/{id}/actividades/{activityId}/adjuntos/{attachmentId}', name: 'app_absences_attachment_download', methods: ['GET'])]
    public function attachmentDownload(string $id, string $activityId, string $attachmentId): Response
    {
        $absence = $this->absences->findById($id);
        if ($absence === null) {
            throw $this->createNotFoundException();
        }

        $activity = $this->activities->findById($activityId);
        if ($activity === null || $activity->getAbsence() !== $absence) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(AbsenceVoter::VIEW, $absence);

        $attachment = $this->attachmentRepository->findById($attachmentId);
        if ($attachment === null || $attachment->getActivity() !== $activity) {
            throw $this->createNotFoundException();
        }

        $response = new Response($attachment->getContent());
        $response->headers->set('Content-Type', $attachment->getMimeType());
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getFilename()),
        );

        return $response;
    }

    /**
     * @return array{errors: array<string,string>, formData: array<string,mixed>, date: ?\DateTimeImmutable, timeSlot: ?\App\Entity\TimeSlot, description: string, subjects: list<\App\Entity\GroupTeacher>}
     */
    private function parseActivityFields(Request $request, Absence $absence, Teacher $owner, AcademicYear $year): array
    {
        $errors = [];

        $dateRaw       = trim($request->request->getString('date'));
        $timeSlotId    = trim($request->request->getString('time_slot_id'));
        $description   = trim($request->request->getString('description'));
        $subjectIdsRaw = $request->request->all('subjects');

        $formData = [
            'date'         => $dateRaw,
            'time_slot_id' => $timeSlotId,
            'description'  => $description,
            'subject_ids'  => array_values(array_filter($subjectIdsRaw, 'is_string')),
        ];

        $date = self::parseDate($dateRaw);
        if ($date === null) {
            $errors['date'] = $this->t('activity.error.date_required');
        } elseif ($date < $absence->getStartDate() || $date > $absence->getEndDate()) {
            $errors['date'] = $this->t('activity.error.date_out_of_range');
        }

        $timeSlot = $timeSlotId !== '' ? $this->timeSlots->findByAcademicYearAndId($year, $timeSlotId) : null;
        if ($timeSlot === null) {
            $errors['time_slot_id'] = $this->t('activity.error.time_slot_required');
        } elseif ($date !== null && $timeSlot->getDayOfWeek() !== ((int) $date->format('N') - 1)) {
            $errors['time_slot_id'] = $this->t('activity.error.time_slot_day_mismatch');
        }

        if (strip_tags($description) === '') {
            $errors['description'] = $this->t('activity.error.description_required');
        }

        $subjects = [];
        foreach ($subjectIdsRaw as $subjectId) {
            if (!is_string($subjectId)) {
                continue;
            }
            $groupTeacher = $this->groupTeachers->findById($subjectId);
            if ($groupTeacher !== null
                && $groupTeacher->getTeacher() === $owner
                && $groupTeacher->getGroup()->getAcademicYear() === $year
            ) {
                $subjects[] = $groupTeacher;
            }
        }

        return [
            'errors'      => $errors,
            'formData'    => $formData,
            'date'        => $date,
            'timeSlot'    => $timeSlot,
            'description' => $description,
            'subjects'    => $subjects,
        ];
    }

    /**
     * @param array<string,string> $errors
     * @return list<ActivityAttachment>
     */
    private function processUploadedAttachments(Activity $activity, Request $request, array &$errors): array
    {
        $attachments = [];

        foreach ($request->files->all('attachments') as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            if ($file->getSize() > self::MAX_ATTACHMENT_SIZE) {
                $errors['attachments'] = $this->t('activity.error.attachment_too_large');
                continue;
            }

            $mimeType = $file->getMimeType() ?? 'application/octet-stream';
            if (!in_array($mimeType, self::ALLOWED_ATTACHMENT_MIME_TYPES, true)) {
                $errors['attachments'] = $this->t('activity.error.attachment_type_not_allowed');
                continue;
            }

            $content       = (string) file_get_contents($file->getPathname());
            $attachments[] = new ActivityAttachment(
                $activity,
                $file->getClientOriginalName(),
                $mimeType,
                $file->getSize(),
                $content,
            );
        }

        return $attachments;
    }

    private static function parseDate(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    private function centreFor(Absence $absence): EducationalCentre
    {
        return $absence->getAcademicYear()->getEducationalCentre();
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
}
