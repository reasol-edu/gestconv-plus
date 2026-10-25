<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;

/**
 * Indica si una sección (Feature) está activada: ajuste features.* con la precedencia habitual
 * (global bloqueado > centro > global > valor por defecto, que es «activada»). Si el ajuste no
 * existe (p. ej. una base de datos sin migrar), la sección sigue activada.
 */
final class FeatureToggle
{
    public function __construct(
        private readonly AppSettingsInterface $settings,
    ) {}

    /** En el contexto de la petición actual (centro seleccionado). */
    public function isEnabled(Feature $feature): bool
    {
        return $this->settings->get($feature->setting()) !== false;
    }

    /** Para un centro concreto, fuera de una petición (tareas programadas, enlaces públicos). */
    public function isEnabledForCentre(Feature $feature, EducationalCentre $centre): bool
    {
        return $this->settings->getForCentre($feature->setting(), $centre) !== false;
    }
}
