<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\AbsenceRepository;
use App\Repository\ActivityAttachmentRepository;
use App\Repository\ActivityRepository;
use App\Repository\SanctionRepository;
use App\Repository\SanctionTaskAttachmentRepository;
use App\Repository\SanctionTaskRepository;
use App\Repository\TimeSlotRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\AttachmentDownloadResponder;
use App\Service\AttachmentZipExporter;
use App\Service\BoardTodayBuilder;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/guardias')]
class GuardsController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TimeSlotRepository $timeSlots,
        private readonly BoardTodayBuilder $boardTodayBuilder,
        private readonly SanctionRepository $sanctions,
        private readonly SanctionTaskRepository $tasks,
        private readonly AbsenceRepository $absences,
        private readonly ActivityRepository $activities,
        private readonly ActivityAttachmentRepository $activityAttachments,
        private readonly SanctionTaskAttachmentRepository $taskAttachments,
        private readonly AttachmentDownloadResponder $downloadResponder,
        private readonly AttachmentZipExporter $zipExporter,
    ) {}

    #[Route('', name: 'app_guards_index')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $year = $this->tenantContext->getViewYear($centre);
        if ($year === null) {
            return $this->render('guards/index.html.twig', [
                'centre' => $centre,
                'year'   => null,
            ]);
        }

        $this->assertAccess($centre, $year);

        $date    = self::parseDate($request->query->getString('date')) ?? new \DateTimeImmutable('today');
        $isToday = $date->format('Y-m-d') === (new \DateTimeImmutable('today'))->format('Y-m-d');
        $isAdmin = $this->isGranted(EducationalCentreVoter::SECTION, $centre);

        $report = $this->boardTodayBuilder->build($year, $date);

        $slots = [];
        foreach ($report->timeSlots as $item) {
            if (!$isAdmin && !$item->timeSlot->getGuards()->contains($user)) {
                continue;
            }

            $rows           = [];
            $hasAttachments = false;
            foreach ($report->absentTeachers as $absentTeacher) {
                $activity = null;
                foreach ($item->activities as $candidate) {
                    if ($candidate->getAbsence()->getTeacher() === $absentTeacher) {
                        $activity = $candidate;
                        break;
                    }
                }
                if ($activity !== null && !$activity->getAttachments()->isEmpty()) {
                    $hasAttachments = true;
                }
                $rows[] = ['teacher' => $absentTeacher, 'activity' => $activity];
            }

            $slots[] = [
                'timeSlot'       => $item->timeSlot,
                'rows'           => $rows,
                'hasAttachments' => $hasAttachments,
            ];
        }

        /** @var array<string, list<array{sanction: \App\Entity\Sanction, tasks: list<\App\Entity\SanctionTask>, hasAttachments: bool}>> $sanctionsByGroup */
        $sanctionsByGroup = [];
        foreach ($this->sanctions->findActiveOn($year, $date) as $sanction) {
            $tasks          = $this->tasks->findBySanction($sanction);
            $hasAttachments = false;
            foreach ($tasks as $task) {
                if (!$task->getAttachments()->isEmpty()) {
                    $hasAttachments = true;
                    break;
                }
            }

            $sanctionsByGroup[$sanction->getGroup()->getName()][] = [
                'sanction'       => $sanction,
                'tasks'          => $tasks,
                'hasAttachments' => $hasAttachments,
            ];
        }
        ksort($sanctionsByGroup);

        return $this->render('guards/index.html.twig', [
            'centre'           => $centre,
            'year'             => $year,
            'date'             => $date,
            'isToday'          => $isToday,
            'isAdmin'          => $isAdmin,
            'slots'            => $slots,
            'sanctionsByGroup' => $sanctionsByGroup,
            'prevDate'         => $date->modify('-1 day')->format('Y-m-d'),
            'nextDate'         => $date->modify('+1 day')->format('Y-m-d'),
        ]);
    }

    #[Route('/ausencias/{absenceId}/actividades/{activityId}/adjuntos/{attachmentId}', name: 'app_guards_activity_attachment_download', methods: ['GET'])]
    public function activityAttachmentDownload(string $absenceId, string $activityId, string $attachmentId): Response
    {
        $absence = $this->absences->findById($absenceId);
        if ($absence === null) {
            throw $this->createNotFoundException();
        }

        $activity = $this->activities->findById($activityId);
        if ($activity === null || $activity->getAbsence() !== $absence) {
            throw $this->createNotFoundException();
        }

        $this->assertAccess($absence->getAcademicYear()->getEducationalCentre(), $absence->getAcademicYear());

        $attachment = $this->activityAttachments->findById($attachmentId);
        if ($attachment === null || $attachment->getActivity() !== $activity) {
            throw $this->createNotFoundException();
        }

        return $this->downloadResponder->respond($attachment->getContent(), $attachment->getMimeType(), $attachment->getFilename());
    }

    #[Route('/ausencias/{absenceId}/actividades/{activityId}/adjuntos.zip', name: 'app_guards_activity_attachments_zip', methods: ['GET'])]
    public function activityAttachmentsZip(string $absenceId, string $activityId): BinaryFileResponse
    {
        $absence = $this->absences->findById($absenceId);
        if ($absence === null) {
            throw $this->createNotFoundException();
        }

        $activity = $this->activities->findById($activityId);
        if ($activity === null || $activity->getAbsence() !== $absence) {
            throw $this->createNotFoundException();
        }

        $this->assertAccess($absence->getAcademicYear()->getEducationalCentre(), $absence->getAcademicYear());

        $entries = [];
        foreach ($activity->getAttachments() as $attachment) {
            $entries[] = ['name' => $attachment->getFilename(), 'content' => $attachment->getContent()];
        }

        return $this->zipExporter->createResponse(
            sprintf('adjuntos-actividad-%s.zip', $activity->getDate()->format('Y-m-d')),
            $entries,
        );
    }

    #[Route('/tramos/{timeSlotId}/adjuntos.zip', name: 'app_guards_time_slot_attachments_zip', methods: ['GET'])]
    public function timeSlotAttachmentsZip(string $timeSlotId, Request $request): BinaryFileResponse
    {
        $timeSlot = $this->timeSlots->findById($timeSlotId);
        if ($timeSlot === null) {
            throw $this->createNotFoundException();
        }

        $year = $timeSlot->getAcademicYear();
        $this->assertAccess($year->getEducationalCentre(), $year);

        $date   = self::parseDate($request->query->getString('date')) ?? new \DateTimeImmutable('today');
        $report = $this->boardTodayBuilder->build($year, $date);

        $entries = [];
        foreach ($report->timeSlots as $item) {
            if ($item->timeSlot !== $timeSlot) {
                continue;
            }

            foreach ($item->activities as $activity) {
                $teacher = $activity->getAbsence()->getTeacher();
                $folder  = sprintf('%s, %s', $teacher->getName()->getLastName(), $teacher->getName()->getFirstName());
                foreach ($activity->getAttachments() as $attachment) {
                    $entries[] = ['name' => sprintf('%s/%s', $folder, $attachment->getFilename()), 'content' => $attachment->getContent()];
                }
            }
        }

        return $this->zipExporter->createResponse(
            sprintf('adjuntos-guardia-%s.zip', $date->format('Y-m-d')),
            $entries,
        );
    }

    #[Route('/sanciones/{sanctionId}/tareas/{taskId}/adjuntos/{attachmentId}', name: 'app_guards_task_attachment_download', methods: ['GET'])]
    public function taskAttachmentDownload(string $sanctionId, string $taskId, string $attachmentId): Response
    {
        $sanction = $this->sanctions->findById($sanctionId);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $task = $this->tasks->findById($taskId);
        if ($task === null || $task->getSanction() !== $sanction) {
            throw $this->createNotFoundException();
        }

        $this->assertAccess($sanction->getAcademicYear()->getEducationalCentre(), $sanction->getAcademicYear());

        $attachment = $this->taskAttachments->findById($attachmentId);
        if ($attachment === null || $attachment->getTask() !== $task) {
            throw $this->createNotFoundException();
        }

        return $this->downloadResponder->respond($attachment->getContent(), $attachment->getMimeType(), $attachment->getFilename());
    }

    #[Route('/sanciones/{sanctionId}/adjuntos.zip', name: 'app_guards_sanction_attachments_zip', methods: ['GET'])]
    public function sanctionAttachmentsZip(string $sanctionId): BinaryFileResponse
    {
        $sanction = $this->sanctions->findById($sanctionId);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $this->assertAccess($sanction->getAcademicYear()->getEducationalCentre(), $sanction->getAcademicYear());

        $entries = [];
        foreach ($this->tasks->findBySanction($sanction) as $task) {
            $folder = $task->getGroupTeacher()->getSubject();
            foreach ($task->getAttachments() as $attachment) {
                $entries[] = ['name' => sprintf('%s/%s', $folder, $attachment->getFilename()), 'content' => $attachment->getContent()];
            }
        }

        return $this->zipExporter->createResponse(
            sprintf('adjuntos-sancion-%s.zip', $sanction->getId()->toRfc4122()),
            $entries,
        );
    }

    private function assertAccess(EducationalCentre $centre, AcademicYear $year): void
    {
        if ($this->isGranted(EducationalCentreVoter::SECTION, $centre)) {
            return;
        }

        $user = $this->getUser();
        if ($user instanceof Teacher && $this->timeSlots->hasGuardDutyInYear($centre, $user, $year)) {
            return;
        }

        throw $this->createAccessDeniedException();
    }

    private static function parseDate(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }
}
