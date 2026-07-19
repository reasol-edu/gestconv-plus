<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EducationalCentre;
use App\Entity\SanctionTask;
use App\Entity\SanctionTaskAttachment;
use App\Entity\Teacher;
use App\Repository\SanctionRepository;
use App\Repository\SanctionTaskAttachmentRepository;
use App\Repository\SanctionTaskRepository;
use App\Security\Voter\SanctionTaskVoter;
use App\Service\ActivityLogService;
use App\Service\AttachmentDownloadResponder;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class SanctionTaskController extends AbstractController
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
        private readonly SanctionRepository $sanctions,
        private readonly SanctionTaskRepository $tasks,
        private readonly SanctionTaskAttachmentRepository $attachmentRepository,
        private readonly TranslatorInterface $translator,
        private readonly ActivityLogService $activityLog,
        private readonly AttachmentDownloadResponder $downloadResponder,
    ) {}

    #[Route('/tareas-de-sancion', name: 'app_sanction_tasks_index')]
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

        $year  = $this->tenantContext->getViewYear($centre);
        $tasks = $year !== null ? $this->tasks->findForTeacher($centre, $user, $year) : [];

        return $this->render('sanction_task/index.html.twig', [
            'centre' => $centre,
            'year'   => $year,
            'tasks'  => $tasks,
        ]);
    }

    #[Route('/sanciones/{id}/tareas/{taskId}/editar', name: 'app_sanction_tasks_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, string $taskId, Request $request): Response
    {
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre === null) {
            return $this->redirectToRoute('app_select_centre');
        }

        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $task = $this->tasks->findById($taskId);
        if ($task === null || $task->getSanction() !== $sanction) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SanctionTaskVoter::EDIT, $task);
        $this->denyIfViewingPastYear($centre);

        $siblingTasks = $this->tasks->findBySanction($sanction);

        $errors   = [];
        $formData = [
            'description'    => $task->getDescription() ?? '',
            'not_applicable' => $task->isNotApplicable(),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('edit_sanction_task_' . $taskId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $notApplicable = $request->request->getBoolean('not_applicable');
            $description   = trim($request->request->getString('description'));
            $formData      = ['description' => $description, 'not_applicable' => $notApplicable];

            if (!$notApplicable && strip_tags($description) === '') {
                $errors['description'] = $this->t('sanction_task.error.description_required');
            }

            $newAttachments = $notApplicable ? [] : $this->processUploadedAttachments($task, $request, $errors);

            /** @var list<string> $removeIds */
            $removeIds = array_values(array_filter($request->request->all('remove_attachments'), 'is_string'));

            if (empty($errors)) {
                $wasCompleted = $task->getCompletedAt() !== null;

                $task->setNotApplicable($notApplicable);
                $task->setDescription($notApplicable ? null : $description);

                foreach ($task->getAttachments()->toArray() as $attachment) {
                    if ($notApplicable || in_array($attachment->getId()->toRfc4122(), $removeIds, true)) {
                        $task->removeAttachment($attachment);
                        $this->em->remove($attachment);
                    }
                }
                foreach ($newAttachments as $attachment) {
                    $task->addAttachment($attachment);
                }

                if (!$wasCompleted) {
                    $task->setCompletedAt(new \DateTimeImmutable());
                }

                $this->em->flush();

                $this->activityLog->log($wasCompleted ? 'sanction_task.updated' : 'sanction_task.completed', [
                    'entityId'   => $task->getId()->toRfc4122(),
                    'sanctionId' => $sanction->getId()->toRfc4122(),
                ]);

                $this->addFlash('success', $this->t('sanction_task.flash.updated'));

                return $this->redirectToRoute('app_sanction_tasks_index');
            }
        }

        return $this->render('sanction_task/edit.html.twig', [
            'centre'       => $centre,
            'sanction'     => $sanction,
            'task'         => $task,
            'siblingTasks' => $siblingTasks,
            'errors'       => $errors,
            'formData'     => $formData,
        ]);
    }

    #[Route('/sanciones/{id}/tareas/{taskId}/adjuntos/{attachmentId}', name: 'app_sanction_tasks_attachment_download', methods: ['GET'])]
    public function attachmentDownload(string $id, string $taskId, string $attachmentId): Response
    {
        $sanction = $this->sanctions->findById($id);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $task = $this->tasks->findById($taskId);
        if ($task === null || $task->getSanction() !== $sanction) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(SanctionTaskVoter::VIEW, $task);

        $attachment = $this->attachmentRepository->findById($attachmentId);
        if ($attachment === null || $attachment->getTask() !== $task) {
            throw $this->createNotFoundException();
        }

        return $this->downloadResponder->respond($attachment->getContent(), $attachment->getMimeType(), $attachment->getFilename());
    }

    /**
     * @param array<string,string> $errors
     * @return list<SanctionTaskAttachment>
     */
    private function processUploadedAttachments(SanctionTask $task, Request $request, array &$errors): array
    {
        $attachments = [];

        foreach ($request->files->all('attachments') as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            if ($file->getSize() > self::MAX_ATTACHMENT_SIZE) {
                $errors['attachments'] = $this->t('sanction_task.error.attachment_too_large');
                continue;
            }

            $mimeType = $file->getMimeType() ?? 'application/octet-stream';
            if (!in_array($mimeType, self::ALLOWED_ATTACHMENT_MIME_TYPES, true)) {
                $errors['attachments'] = $this->t('sanction_task.error.attachment_type_not_allowed');
                continue;
            }

            $content       = (string) file_get_contents($file->getPathname());
            $attachments[] = new SanctionTaskAttachment(
                $task,
                $file->getClientOriginalName(),
                $mimeType,
                $file->getSize(),
                $content,
            );
        }

        return $attachments;
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
