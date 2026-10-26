<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Los eventos pasaron a llamarse «Actividades» y de /eventos a /actividades: las direcciones antiguas redirigen. */
class LegacyEventRedirectController extends AbstractController
{
    #[Route('/eventos/{rest}', name: 'app_events_legacy_redirect', requirements: ['rest' => '.*'], methods: ['GET'])]
    public function redirectToActivities(Request $request, string $rest): RedirectResponse
    {
        $query = $request->getQueryString();

        return new RedirectResponse('/actividades/' . $rest . ($query !== null ? '?' . $query : ''), 301);
    }
}
