<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;

/**
 * Qué puede hacer el personal no docente: solo la sección de incidencias (más su perfil, la elección de centro y salir).
 * Para el resto de la aplicación es como si no existiera, y si el módulo de incidencias está desactivado en un centro no
 * puede entrar en él.
 */
final class NonTeachingAccess
{
    /** Prefijos de las rutas permitidas (además de las públicas, que no dependen de quien ha entrado). */
    private const ALLOWED_ROUTE_PREFIXES = [
        'app_issues',
        'app_select_centre',
        'app_profile',
        'app_force_password_change',
        'app_login',
        'app_logout',
        'app_search',
        'app_public_',
        '_wdt',
        '_profiler',
        '_preview_error',
    ];

    /** Componentes Live permitidos: el listado de incidencias, la campana y los ajustes del perfil. */
    private const ALLOWED_LIVE_COMPONENTS = ['IssueListComponent', 'NotificationBellComponent', 'SettingsComponent'];

    public function __construct(
        private readonly FeatureToggle $features,
    ) {}

    /** Es personal no docente (los administradores de la plataforma nunca lo son). */
    public function isRestricted(mixed $user): bool
    {
        return $user instanceof Teacher && $user->isNonTeaching() && !$user->isAdmin();
    }

    public function allowsRoute(string $route, string $liveComponent = ''): bool
    {
        if ($liveComponent !== '') {
            return \in_array($liveComponent, self::ALLOWED_LIVE_COMPONENTS, true);
        }

        foreach (self::ALLOWED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Las pantallas que no dependen de un centro: no se bloquean aunque el centro seleccionado no admita al usuario. */
    public function isCentreIndependent(string $route): bool
    {
        foreach (['app_select_centre', 'app_profile', 'app_force_password_change', 'app_login', 'app_logout', 'app_public_', '_wdt', '_profiler'] as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Clave de traducción (dominio `centre_selection`) del motivo por el que no puede entrar en el centro, o null si puede. */
    public function blockReason(EducationalCentre $centre): ?string
    {
        return $this->features->isEnabledForCentre(Feature::Issues, $centre) ? null : 'blocked.issues_disabled';
    }
}
