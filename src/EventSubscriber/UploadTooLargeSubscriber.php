<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\UploadLimits;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cuando el cuerpo de un envío con ficheros supera post_max_size, PHP lo descarta entero (POST y
 * ficheros, incluido el token CSRF) sin ningún error propio de la aplicación: el controlador solo
 * vería un formulario vacío y respondería con un 403 ajeno al tamaño de los ficheros. Este listener
 * lo detecta por Content-Length, antes de que llegue a ningún controlador, para todas las subidas
 * (adjuntos, plantillas PDF, importaciones…), y devuelve al usuario a la página anterior con un aviso.
 *
 * Se ejecuta después del cortafuegos (prioridad 8): solo usuarios identificados llegan hasta aquí.
 */
final class UploadTooLargeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UploadLimits $limits,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest()
            || !$request->isMethod('POST')
            || !str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/form-data')
            || !$this->limits->isRequestTooLarge($request)
        ) {
            return;
        }

        if ($request->hasSession()) {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', $this->limits->requestTooLargeMessage($request));
            }
        }

        $event->setResponse(new RedirectResponse($this->backTarget($request), 303));
    }

    /** Ruta de la página desde la que se envió el formulario (solo del mismo sitio) o el inicio. */
    private function backTarget(Request $request): string
    {
        $referer = (string) $request->headers->get('Referer');
        $parts   = parse_url($referer);

        if (is_array($parts) && ($parts['host'] ?? null) === $request->getHost() && isset($parts['path'])) {
            return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        return $request->getBasePath() . '/';
    }
}
