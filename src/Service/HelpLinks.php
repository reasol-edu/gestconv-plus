<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Enlaces de «ayuda» de cada pantalla al apartado del manual que la explica. La dirección base es
 * `APP_HELP_URL` (por defecto, el manual publicado en la web del proyecto), de modo que una instalación en
 * una intranet sin salida a internet pueda apuntar a una copia propia del manual web.
 *
 * Es el único sitio con la correspondencia ruta → apartado: cada ancla debe existir como `{#ancla}` en el
 * capítulo indicado (lo comprueba HelpLinksTest, para que los enlaces no se rompan sin que nadie lo note).
 */
final class HelpLinks
{
    /**
     * Prefijo de nombre de ruta → [capítulo del manual sin extensión, ancla]. Gana el prefijo más largo.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MAP = [
        'app_dashboard'                      => ['03-usar-la-aplicacion', 'la-pantalla-de-inicio'],
        'app_incidents_new'                  => ['04-convivencia-profesorado', 'registrar-un-nuevo-parte'],
        'app_incidents'                      => ['04-convivencia-profesorado', 'partes-de-convivencia'],
        'app_daily_notes'                    => ['04-convivencia-profesorado', 'notas'],
        'app_notifications'                  => ['04-convivencia-profesorado', 'notificaciones'],
        'app_students_priority_pass'         => ['07-salidas', 'pase-prioritario'],
        'app_students'                       => ['04-convivencia-profesorado', 'ficha-del-estudiante'],
        'app_tutorship'                      => ['04-convivencia-profesorado', 'mi-tutoria'],
        'app_sanction_tasks'                 => ['04-convivencia-profesorado', 'tareas-de-sancion'],
        'app_sanctions_new'                  => ['05-convivencia-comision', 'registrar-una-sancion'],
        'app_sanctions'                      => ['05-convivencia-comision', 'listado-de-sanciones'],
        'app_exits'                          => ['07-salidas', 'autorizar-una-salida'],
        'app_absences'                       => ['08-ausencias-y-guardias', 'ausencias'],
        'app_guards'                         => ['08-ausencias-y-guardias', 'guardias'],
        'app_bookings'                       => ['09-reservas', 'reservas-de-recursos'],
        'app_issues'                         => ['10-incidencias', 'incidencias'],
        'app_calendar'                       => ['11-calendario-actividades-y-tablon', 'calendario'],
        'app_events'                         => ['11-calendario-actividades-y-tablon', 'actividades-de-centro'],
        'app_reports_analytics'              => ['06-convivencia-administracion', 'analitica-de-convivencia'],
        'app_reports'                        => ['06-convivencia-administracion', 'informes'],
        'app_utilities_calendar_generator'   => ['14-utilidades', 'generador-de-calendarios'],
        'app_utilities_date'                 => ['14-utilidades', 'calculadora-de-fechas'],
        'app_utilities'                      => ['14-utilidades', 'utilidades'],
        'app_admin_settings'                 => ['13-administrar-la-plataforma', 'sistema-de-ajustes'],
        'app_profile_settings'               => ['13-administrar-la-plataforma', 'sistema-de-ajustes'],
        'app_admin'                          => ['13-administrar-la-plataforma', 'administrar-la-plataforma'],
        'app_centre'                         => ['12-administrar-el-centro', 'administrar-el-centro-educativo'],
    ];

    public function __construct(
        #[Autowire('%app.help_url%')]
        private readonly string $baseUrl,
    ) {}

    /** Dirección del apartado del manual para una ruta, o null si esa pantalla no tiene ayuda asociada. */
    public function urlForRoute(?string $route): ?string
    {
        $target = $this->targetForRoute($route);
        if ($target === null) {
            return null;
        }

        return rtrim($this->baseUrl, '/') . '/' . $target[0] . '.html#' . $target[1];
    }

    /** @return array{0: string, 1: string}|null */
    public function targetForRoute(?string $route): ?array
    {
        if ($route === null) {
            return null;
        }

        $best    = null;
        $bestLen = -1;
        foreach (self::MAP as $prefix => $target) {
            if (($route === $prefix || str_starts_with($route, $prefix . '_')) && strlen($prefix) > $bestLen) {
                $best    = $target;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }
}
