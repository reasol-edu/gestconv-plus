<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Marca la sesión del navegador como bloqueada en "modo tablón": una vez
 * activo, KioskModeSubscriber redirige cualquier petición que no sea el
 * propio tablón o el cierre de sesión, de modo que solo se puede salir
 * cerrando sesión.
 */
final class KioskMode
{
    private const SESSION_KEY = 'kiosk.locked';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    public function isActive(): bool
    {
        return $this->requestStack->getSession()->get(self::SESSION_KEY, false) === true;
    }

    public function activate(): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, true);
    }
}
