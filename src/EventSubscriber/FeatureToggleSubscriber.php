<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Repository\EducationalCentreRepository;
use App\Service\Feature;
use App\Service\FeatureToggle;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Cierra por completo las secciones desactivadas con los ajustes features.* (Salidas, Ausencias,
 * Notas del día y tareas de sanción): sus rutas responden 404, como si no existieran, además de
 * ocultarse sus enlaces. Se ejecuta después de que TenantContextSubscriber (prioridad 4) haya
 * fijado el centro, porque el ajuste puede estar desactivado solo para un centro.
 *
 * El enlace público de tareas (/t/…) no se trata aquí: no tiene centro en la sesión, así que lo
 * comprueba su propio controlador con el centro de la sanción.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 3)]
final class FeatureToggleSubscriber
{
    public function __construct(
        private readonly FeatureToggle $features,
        private readonly EducationalCentreRepository $centres,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $attributes = $event->getRequest()->attributes;
        $route      = $attributes->get('_route');
        $component  = $attributes->get('_live_component');

        foreach (Feature::cases() as $feature) {
            if (!$this->belongsTo($feature, is_string($route) ? $route : '', is_string($component) ? $component : '')) {
                continue;
            }

            if (!$this->features->isEnabled($feature)) {
                throw new NotFoundHttpException();
            }
        }

        $this->guardCatalogs(is_string($route) ? $route : '', $attributes->get('centreId'));
    }

    /** Los catálogos de una sección desactivada en el centro de la dirección también responden 404. */
    private function guardCatalogs(string $route, mixed $centreId): void
    {
        if ($route === '' || !is_string($centreId) || !Uuid::isValid($centreId)) {
            return;
        }

        foreach (Feature::cases() as $feature) {
            foreach ($feature->catalogRoutePrefixes() as $prefix) {
                if (!str_starts_with($route, $prefix)) {
                    continue;
                }

                $centre = $this->centres->findById($centreId);
                if ($centre !== null && !$this->features->isEnabledForCentre($feature, $centre)) {
                    throw new NotFoundHttpException();
                }
            }
        }
    }

    private function belongsTo(Feature $feature, string $route, string $component): bool
    {
        if ($component !== '' && in_array($component, $feature->liveComponents(), true)) {
            return true;
        }

        foreach ($feature->routePrefixes() as $prefix) {
            if ($route !== '' && str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
