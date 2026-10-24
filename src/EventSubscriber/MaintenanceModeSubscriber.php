<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\MaintenanceMode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * En modo mantenimiento (ajuste maintenance.enabled) solo pasan los administradores globales: el
 * resto de peticiones recibe una página 503. Se deja abierto únicamente lo que un administrador
 * necesita para entrar y lo que la propia página necesita para verse: el acceso y la salida, los
 * estáticos y los recursos compilados, y las herramientas de depuración de Symfony. Todo lo demás
 * se bloquea a propósito, incluidas las actualizaciones de los Live Components, el enlace público
 * de tareas del alumnado y la recuperación de contraseña.
 *
 * Un administrador que usa «Acceder como» conserva el acceso (el usuario suplantado no es
 * administrador, pero quien lo suplanta sí). Los visitantes anónimos no se tratan aquí: el firewall
 * los envía antes a la pantalla de acceso, que ya explica la situación.
 */
final class MaintenanceModeSubscriber implements EventSubscriberInterface
{
    /** Rutas exactas que siguen abiertas: la entrada (y la salida) de los administradores. */
    private const array OPEN_PATHS = ['/login', '/logout'];

    /** Prefijos que siguen abiertos: lo que cargan las páginas y las herramientas de depuración de Symfony. */
    private const array OPEN_PREFIXES = ['/assets/', '/static/', '/_wdt', '/_profiler'];

    public function __construct(
        private readonly MaintenanceMode $maintenance,
        private readonly Security $security,
        private readonly Environment $twig,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Después del firewall (prioridad 8), que ya ha autenticado la petición, y antes del modo tablón (5).
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $this->isOpenPath($request) || !$this->maintenance->isActive()) {
            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('IS_IMPERSONATOR')) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('maintenance/index.html.twig', ['signed_in' => $this->security->getUser() !== null]),
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Retry-After' => '300'],
        ));
    }

    private function isOpenPath(Request $request): bool
    {
        $path = $request->getPathInfo();
        if (in_array($path, self::OPEN_PATHS, true)) {
            return true;
        }

        foreach (self::OPEN_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
