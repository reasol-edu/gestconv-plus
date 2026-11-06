<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Teacher;
use App\Service\NonTeachingAccess;
use App\Service\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Limita al personal no docente a la sección de incidencias:
 *  - si el centro en el que están no tiene activado el módulo de incidencias, no entran en él: se deja la elección de
 *    centro y se les explica el motivo;
 *  - el inicio es el listado de incidencias, y cualquier otra pantalla de la aplicación responde 403.
 *
 * Va después de la elección del centro (TenantContextSubscriber, prioridad 4) y antes del interruptor de secciones
 * (FeatureToggleSubscriber), para explicar el motivo en lugar de responder 404.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 3)]
final class NonTeachingAccessSubscriber
{
    public function __construct(
        private readonly NonTeachingAccess $access,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Teacher || !$this->access->isRestricted($user)) {
            return;
        }

        $attributes = $event->getRequest()->attributes;
        $route      = is_string($attributes->get('_route')) ? $attributes->get('_route') : '';
        $component  = is_string($attributes->get('_live_component')) ? $attributes->get('_live_component') : '';

        if ($this->access->isCentreIndependent($route)) {
            return;
        }

        // Un centro sin el módulo de incidencias no admite a quien solo puede usar ese módulo.
        $centre = $this->tenantContext->getSelectedCentre();
        if ($centre !== null && ($reason = $this->access->blockReason($centre)) !== null) {
            $this->tenantContext->clear();
            $session = $event->getRequest()->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', $this->translator->trans($reason, ['%centre%' => $centre->getName()], 'centre_selection'));
            }
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_select_centre')));

            return;
        }

        if ($route === 'app_dashboard') {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_issues_index')));

            return;
        }

        if (!$this->access->allowsRoute($route, $component)) {
            throw new AccessDeniedHttpException();
        }
    }
}
