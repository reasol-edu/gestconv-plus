<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Indica si toda la aplicación está en modo mantenimiento (ajuste maintenance.enabled, solo global):
 * mientras lo está, solo pueden usarla los administradores globales (ver MaintenanceModeSubscriber).
 * Está desactivado si el ajuste no existe (p. ej. una base de datos sin migrar), para que una
 * definición ausente nunca deje a nadie fuera.
 */
final class MaintenanceMode
{
    public const string SETTING = 'maintenance.enabled';

    public function __construct(
        private readonly AppSettingsInterface $settings,
    ) {}

    public function isActive(): bool
    {
        return $this->settings->getGlobal(self::SETTING) === true;
    }
}
