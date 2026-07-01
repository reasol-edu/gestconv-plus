<?php

namespace App\EventSubscriber;

use App\Entity\Teacher;
use App\Service\KioskMode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 5)]
final class KioskModeSubscriber
{
    private const ALLOWED_ROUTES = [
        'app_calendar_board',
        'app_logout',
        'app_login',
    ];

    public function __construct(
        private readonly KioskMode $kioskMode,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (\in_array($route, self::ALLOWED_ROUTES, strict: true)) {
            return;
        }

        if (!$this->security->getUser() instanceof Teacher) {
            return;
        }

        if (!$this->kioskMode->isActive()) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_calendar_board')
        ));
    }
}
