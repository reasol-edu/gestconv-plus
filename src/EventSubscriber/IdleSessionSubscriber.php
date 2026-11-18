<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Teacher;
use App\Service\AppSettingsInterface;
use App\Service\KioskMode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Cierra la sesión de quien lleva un rato sin hacer nada (ajuste `security.idle_timeout_minutes`, en minutos; 0 lo desactiva):
 * un ordenador de la sala de profesores que se queda con la sesión abierta no debe poder usarlo la siguiente persona. El ajuste
 * se puede dar a nivel global, de centro y de docente (se aplica el de más cerca, salvo que uno superior lo bloquee), así que
 * quien necesite una pantalla siempre abierta (conserjería) puede ponerse un 0 propio si nadie lo ha bloqueado.
 *
 * Cuenta como actividad cualquier petición, también las de los componentes en vivo, salvo las que hace la propia página por
 * su cuenta para mantenerse al día (cabecera `X-Background-Refresh` o un componente de BACKGROUND_COMPONENTS): si contaran, una
 * pantalla que se refresca sola no caducaría nunca. El modo tablón (pantalla de pasillo que se deja encendida) no caduca.
 *
 * El cierre pasa por Security::logout(), así que queda anotado como cualquier otro, y la pantalla de inicio de sesión explica el
 * motivo (`?sesion=caducada`).
 */
final class IdleSessionSubscriber implements EventSubscriberInterface
{
    public const string SETTING = 'security.idle_timeout_minutes';

    /** Cabecera con la que el JavaScript de la aplicación marca las peticiones de refresco automático. */
    public const string BACKGROUND_HEADER = 'X-Background-Refresh';

    /** Componentes en vivo que se refrescan solos (`data-poll`): su petición de «get» no cuenta como actividad. */
    private const array BACKGROUND_COMPONENTS = ['ExitCurrentList'];

    private const string SESSION_KEY = 'security.last_activity';

    /** Si el ajuste no existe (p. ej. antes de migrar). */
    private const int DEFAULT_MINUTES = 120;

    public function __construct(
        private readonly Security $security,
        private readonly AppSettingsInterface $settings,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly KioskMode $kioskMode,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Después del cortafuegos (prioridad 8), que ya ha autenticado la petición.
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession() || !$this->security->getUser() instanceof Teacher) {
            return;
        }
        if ($this->kioskMode->isActive()) {
            return;
        }

        $session = $request->getSession();
        $now     = $this->clock->now()->getTimestamp();
        $last    = $session->get(self::SESSION_KEY);
        $minutes = $this->timeoutMinutes();

        if ($minutes > 0 && is_int($last) && $now - $last > $minutes * 60) {
            $this->security->logout(false);
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login', ['sesion' => 'caducada'])));

            return;
        }

        if (!$this->isBackgroundRefresh($request) || !is_int($last)) {
            $session->set(self::SESSION_KEY, $now);
        }
    }

    private function isBackgroundRefresh(Request $request): bool
    {
        if ($request->headers->get(self::BACKGROUND_HEADER) === '1') {
            return true;
        }

        return in_array($request->attributes->get('_live_component'), self::BACKGROUND_COMPONENTS, true)
            && $request->attributes->get('_live_action') === 'get';
    }

    private function timeoutMinutes(): int
    {
        $minutes = $this->settings->get(self::SETTING);

        return is_int($minutes) && $minutes >= 0 ? $minutes : self::DEFAULT_MINUTES;
    }
}
