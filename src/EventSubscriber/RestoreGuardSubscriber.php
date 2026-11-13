<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Backup\RestoreState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Protege la aplicación de una restauración de copia de seguridad:
 *
 * 1. Mientras hay una en marcha, TODAS las peticiones reciben una página 503 (también las de los administradores): están
 *    borrándose y reinsertándose las tablas, y cualquier lectura o escritura daría resultados a medias. Se ejecuta **antes del
 *    firewall** (que recarga el usuario desde la tabla `teacher`) y solo mira un fichero: ni base de datos ni sesión.
 * 2. Cuando termina una restauración, se cierran las sesiones creadas antes: los usuarios de la copia pueden no ser los mismos
 *    (o tener otros permisos). La hora de creación de una sesión se renueva al iniciar sesión, así que quien entra después de la
 *    restauración no se ve afectado.
 */
final class RestoreGuardSubscriber implements EventSubscriberInterface
{
    /** Estáticos de las páginas: la de 503 es autónoma, pero un navegador puede pedir el icono o el CSS ya cargado. */
    private const array OPEN_PREFIXES = ['/assets/', '/static/', '/_wdt', '/_profiler'];

    public function __construct(
        private readonly RestoreState $state,
        private readonly Environment $twig,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // El firewall de seguridad va en 8; el de sesión en 128.
        return [KernelEvents::REQUEST => [
            ['blockWhileRestoring', 256],
            ['closeStaleSessions', 9],
        ]];
    }

    public function blockWhileRestoring(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $this->isOpenPath($request) || !$this->state->isRunning()) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('restore/in_progress.html.twig'),
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Retry-After' => '15', 'Cache-Control' => 'no-store'],
        ));
    }

    public function closeStaleSessions(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }

        $restoredAt = $this->state->restoredAt();
        if ($restoredAt === null) {
            return;
        }

        $session = $request->getSession();
        // Sin arrancar la sesión, los metadatos están vacíos y `getCreated()` vale 0: parecería anterior a cualquier restauración y
        // cerraría hasta las sesiones nuevas (solo se nota con el almacenamiento nativo de PHP, no con el de las pruebas).
        if (!$session->isStarted()) {
            $session->start();
        }
        // `getCreated()` es la hora en que PHP creó (o regeneró, al iniciar sesión) la sesión: se compara con time(), no con el reloj simulado.
        if ($session->getMetadataBag()->getCreated() < $restoredAt) {
            $session->invalidate();
            $event->setResponse(new RedirectResponse('/login'));
        }
    }

    private function isOpenPath(Request $request): bool
    {
        foreach (self::OPEN_PREFIXES as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
