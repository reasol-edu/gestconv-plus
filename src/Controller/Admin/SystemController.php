<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\ActivityLogService;
use App\Service\Backup\BackupException;
use App\Service\Backup\DatabaseBackup;
use App\Service\Update\LatestReleaseChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Mantenimiento de la plataforma, solo para administradores globales: copia de seguridad y versión instalada. */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/sistema')]
class SystemController extends AbstractController
{
    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly ActivityLogService $activityLog,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly LatestReleaseChecker $latestRelease,
        #[Autowire('%app.version%')]
        private readonly string $appVersion,
    ) {}

    #[Route('', name: 'app_admin_system', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/system/index.html.twig', [
            'version' => $this->appVersion,
            'update'  => $this->latestRelease->status(),
        ]);
    }

    /**
     * Descarga una copia de seguridad completa. Es un POST con token CSRF para que ningún enlace (ni una precarga del navegador)
     * pueda lanzarla, y queda en el registro de actividad: la copia lleva datos personales del alumnado y hashes de contraseñas.
     */
    #[Route('/copia', name: 'app_admin_system_backup', methods: ['POST'])]
    public function backup(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('system_backup', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Con muchos adjuntos puede tardar más que el límite por defecto.
        set_time_limit(0);

        $file = tempnam(sys_get_temp_dir(), 'gestconv-copia-');
        if ($file === false) {
            $this->addFlash('error', $this->translator->trans('system.backup.error', ['%reason%' => 'tempnam'], 'admin'));

            return $this->redirectToRoute('app_admin_system');
        }

        try {
            $manifest = $this->backup->create($file);
        } catch (BackupException $e) {
            @unlink($file);
            $this->addFlash('error', $this->translator->trans('system.backup.error', ['%reason%' => $e->getMessage()], 'admin'));

            return $this->redirectToRoute('app_admin_system');
        }

        $this->activityLog->log('backup.downloaded', [
            'version' => $manifest->appVersion,
            'tables'  => count($manifest->tables),
            'rows'    => $manifest->totalRows(),
        ]);

        $response = new BinaryFileResponse($file);
        $response->deleteFileAfterSend(true);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('gestconv-copia-%s.zip', $this->clock->now()->format('Ymd-Hi')),
        );
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
