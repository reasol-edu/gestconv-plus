<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\SanctionTaskAttachment;
use App\Repository\SanctionRepository;
use App\Repository\SanctionTaskAttachmentRepository;
use App\Repository\SanctionTaskRepository;
use App\Security\Voter\SanctionVoter;
use App\Service\AttachmentDownloadResponder;
use App\Service\AttachmentZipExporter;
use App\Service\SanctionShareCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Acceso sin autenticación del alumno a las tareas de su sanción, mediante un código corto
 * (ver SanctionShareCode) que generan el tutor/a, la directiva o la comisión de convivencia.
 * Solo expone nombre, grupo, fechas y tareas; el acceso se cierra al acabar la sanción.
 */
#[Route('/t')]
class PublicSanctionTasksController extends AbstractController
{
    public function __construct(
        private readonly SanctionRepository $sanctions,
        private readonly SanctionTaskRepository $tasks,
        private readonly SanctionTaskAttachmentRepository $taskAttachments,
        private readonly SanctionShareCode $shareCode,
        private readonly AttachmentDownloadResponder $downloadResponder,
        private readonly AttachmentZipExporter $zipExporter,
        #[Target('sanction_share')]
        private readonly RateLimiterFactoryInterface $sanctionShareLimiter,
    ) {}

    #[Route('', name: 'app_public_tasks_entry', methods: ['GET', 'POST'])]
    public function entry(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->noStore($this->render('public/sanction_tasks_entry.html.twig', ['error' => false, 'input' => '']));
        }

        $input      = $request->request->getString('code');
        $normalized = $this->shareCode->normalize($input);

        // La búsqueda y el recuento de intentos fallidos se hacen en la página del código.
        if ($normalized !== null) {
            return $this->redirectToRoute('app_public_tasks_view', ['code' => $this->shareCode->format($normalized)]);
        }

        $this->registerFailure($request);

        return $this->noStore($this->render('public/sanction_tasks_entry.html.twig', ['error' => true, 'input' => $input], new Response(status: Response::HTTP_NOT_FOUND)));
    }

    #[Route('/{code}', name: 'app_public_tasks_view', requirements: ['code' => '[A-Za-z0-9\-]+'], methods: ['GET'])]
    public function view(string $code, Request $request): Response
    {
        $sanction = $this->open($code, $request);
        if ($sanction === null) {
            return $this->noStore($this->render('public/sanction_tasks_entry.html.twig', ['error' => true, 'input' => $code], new Response(status: Response::HTTP_NOT_FOUND)));
        }

        $attachments = $this->taskAttachments->findBySanctionGroupedByTask($sanction);

        $visible = [];
        foreach ($this->tasks->findBySanction($sanction) as $task) {
            $taskAttachments = $attachments[$task->getId()->toRfc4122()] ?? [];
            if ($task->isNotApplicable() || (trim((string) $task->getDescription()) === '' && $taskAttachments === [])) {
                continue;
            }

            $visible[] = ['task' => $task, 'attachments' => $taskAttachments];
        }

        return $this->noStore($this->render('public/sanction_tasks.html.twig', [
            'sanction'    => $sanction,
            'code'        => $this->shareCode->format((string) $sanction->getShareCode()),
            'entries'     => $visible,
            'hasFiles'    => $attachments !== [],
            'expired'     => !$this->shareCode->isOpen($sanction),
        ]));
    }

    #[Route('/{code}/tareas.zip', name: 'app_public_tasks_zip', requirements: ['code' => '[A-Za-z0-9\-]+'], methods: ['GET'])]
    public function zip(string $code, Request $request): Response
    {
        $sanction = $this->open($code, $request);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $attachments = $this->taskAttachments->findBySanctionGroupedByTask($sanction);

        $entries = [];
        foreach ($this->tasks->findBySanction($sanction) as $task) {
            foreach ($attachments[$task->getId()->toRfc4122()] ?? [] as $attachment) {
                $entries[] = [
                    'name'    => sprintf('%s/%s', $task->getGroupTeacher()->getSubject(), $attachment->getFilename()),
                    'content' => $attachment->getContent(),
                ];
            }
        }

        return $this->noStore($this->zipExporter->createResponse(
            sprintf('tareas-%s.zip', $this->shareCode->format((string) $sanction->getShareCode())),
            $entries,
        ));
    }

    #[Route('/{code}/{attachmentId}', name: 'app_public_tasks_attachment', requirements: ['code' => '[A-Za-z0-9\-]+', 'attachmentId' => '[0-9a-fA-F\-]{36}'], methods: ['GET'])]
    public function attachment(string $code, string $attachmentId, Request $request): Response
    {
        $sanction = $this->open($code, $request);
        if ($sanction === null) {
            throw $this->createNotFoundException();
        }

        $attachment = $this->taskAttachments->findById($attachmentId);
        if (!$attachment instanceof SanctionTaskAttachment || $attachment->getTask()->getSanction() !== $sanction) {
            throw $this->createNotFoundException();
        }

        return $this->noStore($this->downloadResponder->respond($attachment->getContent(), $attachment->getMimeType(), $attachment->getFilename()));
    }

    /**
     * Sanción a la que da acceso el código, o null si no existe, ya no está vigente o no tiene el
     * formato de un código. Los fallos cuentan para el límite de intentos por IP; los aciertos no,
     * porque cada descarga repite la comprobación del código.
     */
    private function open(string $code, Request $request): ?Sanction
    {
        $limiter = $this->sanctionShareLimiter->create($request->getClientIp() ?? 'anon');
        if ($limiter->consume(0)->getRemainingTokens() < 1) {
            throw new TooManyRequestsHttpException(900);
        }

        $normalized = $this->shareCode->normalize($code);
        $sanction   = $normalized === null ? null : $this->sanctions->findByShareCode($normalized);

        // Quien puede gestionar el enlace (con sesión iniciada) lo sigue viendo tras el fin de la sanción.
        if ($sanction !== null && ($this->shareCode->isOpen($sanction) || $this->isGranted(SanctionVoter::SHARE_TASKS, $sanction))) {
            return $sanction;
        }

        $limiter->consume();

        return null;
    }

    private function registerFailure(Request $request): void
    {
        $limiter = $this->sanctionShareLimiter->create($request->getClientIp() ?? 'anon');
        if ($limiter->consume(0)->getRemainingTokens() < 1) {
            throw new TooManyRequestsHttpException(900);
        }

        $limiter->consume();
    }

    /** Datos de un menor: nada de cachés compartidas, ni de indexación, ni de referer. */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
