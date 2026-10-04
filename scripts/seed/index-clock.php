<?php

use App\Kernel;

require_once dirname(__DIR__, 2) . '/vendor/autoload_runtime.php';

// Solo para capturas: la aplicación cree que «ahora» es SHOTS_NOW (por defecto, el lunes 2026-10-05 09:30 UTC).
// Es el reloj de Symfony (Clock), no el del sistema; la zona horaria debe ser la de la aplicación (UTC).
\Symfony\Component\Clock\Clock::set(new \Symfony\Component\Clock\MockClock(getenv('SHOTS_NOW') ?: '2026-10-05 09:30:00', 'UTC'));

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
