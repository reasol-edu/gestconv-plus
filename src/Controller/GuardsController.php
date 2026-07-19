<?php

declare(strict_types=1);

namespace App\Controller;

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
use App\Service\BoardTodayBuilder;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
    ) {}

    #[Route('', name: 'app_guards_index')]
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

            $rows = [];
            foreach ($report->absentTeachers as $absentTeacher) {
                $activity = null;
                foreach ($item->activities as $candidate) {
                    if ($candidate->getAbsence()->getTeacher() === $absentTeacher) {
                        $activity = $candidate;
                        break;
                    }
                }
                $rows[] = ['teacher' => $absentTeacher, 'activity' => $activity];
            }

            $slots[] = [
                'timeSlot' => $item->timeSlot,
                'rows'     => $rows,
            ];
        }

        /** @var array<string, list<array{sanction: \App\Entity\Sanction, tasks: list<\App\Entity\SanctionTask>}>> $sanctionsByGroup */
        $sanctionsByGroup = [];
        foreach ($this->sanctions->findActiveOn($year, $date) as $sanction) {
            $sanctionsByGroup[$sanction->getGroup()->getName()][] = [
                'sanction' => $sanction,
                'tasks'    => $this->tasks->findBySanction($sanction),
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

        return $this->downloadResponse($attachment->getContent(), $attachment->getMimeType(), $attachment->getFilename());
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

        return $this->downloadResponse($attachment->getContent(), $attachment->getMimeType(), $attachment->getFilename());
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

    private function downloadResponse(string $content, string $mimeType, string $filename): Response
    {
        $response = new Response($content);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename,
                $this->asciiFilenameFallback($filename),
            ),
        );

        return $response;
    }

    /**
     * makeDisposition() exige un nombre de reserva ASCII: el nombre original
     * del adjunto proviene del archivo subido por el usuario y puede
     * contener acentos u otros caracteres no ASCII.
     */
    private function asciiFilenameFallback(string $filename): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
        $ascii = preg_replace('/[^A-Za-z0-9 ._-]/', '', $ascii === false ? $filename : $ascii);

        return $ascii === '' || $ascii === null ? 'adjunto' : $ascii;
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
