<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Teacher;
use App\Service\ActivityLogService;
use App\Service\Backup\BackupException;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\DatabaseBackup;
use App\Service\Backup\RestoreLauncher;
use App\Service\Backup\RestoreState;
use App\Service\Backup\StoredBackup;
use App\Service\Update\LatestReleaseChecker;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mantenimiento de la plataforma, solo para administradores globales: copia de seguridad (descargarla, guardarla en el servidor y
 * restaurar una de las guardadas) y versión instalada.
 *
 * Restaurar sustituye TODOS los datos, así que va protegido: solo copias que ya estén en la carpeta del servidor (nada se sube por
 * la web), confirmación con la contraseña de quien la pide y una palabra escrita, y la restauración la hace un proceso aparte con
 * la aplicación en mantenimiento (ver RestoreGuardSubscriber).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/sistema')]
class SystemController extends AbstractController
{
    /** La palabra que hay que escribir para confirmar una restauración. */
    public const string CONFIRM_WORD = 'RESTAURAR';

    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly BackupLibrary $library,
        private readonly RestoreState $restoreState,
        private readonly RestoreLauncher $launcher,
        private readonly ActivityLogService $activityLog,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly LatestReleaseChecker $latestRelease,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.version%')]
        private readonly string $appVersion,
    ) {}

    #[Route('', name: 'app_admin_system', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/system/index.html.twig', [
            'version'        => $this->appVersion,
            'update'         => $this->latestRelease->status(),
            'copies'         => $this->library->list(),
            'backup_dir'     => $this->library->directory(),
            'can_restore'    => $this->launcher->isAvailable(),
            'last_restore'   => $this->restoreState->lastResult(),
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

    /** Guarda una copia de los datos actuales en la carpeta de copias del servidor, para poder restaurarla desde la web. */
    #[Route('/copias/guardar', name: 'app_admin_system_backup_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('system_backup_save', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        set_time_limit(0);

        try {
            $stored = $this->library->save();
        } catch (BackupException $e) {
            $this->addFlash('error', $this->translator->trans('system.backup.error', ['%reason%' => $e->getMessage()], 'admin'));

            return $this->redirectToRoute('app_admin_system');
        }

        $manifest = $stored->manifest;
        $this->activityLog->log('backup.saved', [
            'version' => $this->appVersion,
            'tables'  => $manifest === null ? 0 : count($manifest->tables),
            'rows'    => $manifest === null ? 0 : $manifest->totalRows(),
        ]);
        $this->addFlash('success', $this->translator->trans('system.copies.saved', ['%name%' => $stored->name], 'admin'));

        return $this->redirectToRoute('app_admin_system');
    }

    #[Route('/copias/{name}/eliminar', name: 'app_admin_system_backup_delete', methods: ['POST'], requirements: ['name' => '[^/]+'])]
    public function delete(string $name, Request $request): Response
    {
        $stored = $this->requireCopy($name);
        if (!$this->isCsrfTokenValid('system_backup_delete_' . $stored->name, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->library->delete($stored->name);
        $this->addFlash('success', $this->translator->trans('system.copies.deleted', ['%name%' => $stored->name], 'admin'));

        return $this->redirectToRoute('app_admin_system');
    }

    /** Revisión antes de restaurar: qué copia es, qué incompatibilidades tiene y qué va a pasar. No cambia nada. */
    #[Route('/copias/{name}/restaurar', name: 'app_admin_system_restore', methods: ['GET', 'POST'], requirements: ['name' => '[^/]+'])]
    public function restore(string $name, Request $request): Response
    {
        $stored = $this->requireCopy($name);
        $user   = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->launcher->isAvailable()) {
            $this->addFlash('error', $this->translator->trans('system.restore.unavailable', [], 'admin'));

            return $this->redirectToRoute('app_admin_system');
        }
        if (!$stored->isRestorable()) {
            $this->addFlash('error', $this->translator->trans('system.restore.not_restorable', [], 'admin'));

            return $this->redirectToRoute('app_admin_system');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $error = $this->tryRestore($stored, $user, $request);
            if ($error === null) {
                return $this->redirectToRoute('app_admin_system');
            }
        }

        return $this->render('admin/system/restore.html.twig', [
            'copy'         => $stored,
            'manifest'     => $stored->manifest,
            'confirm_word' => self::CONFIRM_WORD,
            'error'        => $error,
            'has_password' => $user->getPassword() !== null,
            'user_in_copy' => $this->library->containsUser($stored, $user->getUserIdentifier()),
            'username'     => $user->getUserIdentifier(),
        ]);
    }

    /** @return string|null el motivo por el que no se pudo (ya traducido), o null si la restauración quedó lanzada */
    private function tryRestore(StoredBackup $copy, Teacher $user, Request $request): ?string
    {
        $t = fn (string $key): string => $this->translator->trans($key, [], 'admin');

        if (!$this->isCsrfTokenValid('system_restore_' . $copy->name, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($user->getPassword() === null) {
            return $t('system.restore.error.no_password');
        }
        if (!$this->passwordHasher->isPasswordValid($user, $request->request->getString('password'))) {
            return $t('system.restore.error.password');
        }
        if (trim($request->request->getString('confirm')) !== self::CONFIRM_WORD) {
            return $t('system.restore.error.word');
        }

        // El bloqueo se toma aquí, antes de lanzar el proceso: así el sitio ya está en mantenimiento cuando éste arranca.
        if (!$this->restoreState->acquire($user->getUserIdentifier(), $copy->name)) {
            return $t('system.restore.error.running');
        }

        try {
            $this->launcher->launch($this->library->path($copy), $user->getUserIdentifier());
        } catch (BackupException $e) {
            // Si el proceso no arrancó, nadie liberaría el bloqueo y el sitio se quedaría en 503.
            $this->restoreState->release();

            return $this->translator->trans('system.restore.error.launch', ['%reason%' => $e->getMessage()], 'admin');
        }

        $this->logger->warning('Restauración de copia de seguridad solicitada desde la web.', ['by' => $user->getUserIdentifier(), 'file' => $copy->name, 'ip' => $request->getClientIp()]);

        return null;
    }

    private function requireCopy(string $name): StoredBackup
    {
        return $this->library->find($name) ?? throw $this->createNotFoundException();
    }
}
