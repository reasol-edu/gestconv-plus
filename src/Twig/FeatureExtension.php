<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\EducationalCentre;
use App\Service\Feature;
use App\Service\FeatureToggle;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `feature_enabled('exits'|'absences'|'daily_notes'|'sanction_tasks')`: oculta enlaces y bloques de las secciones desactivadas. */
final class FeatureExtension extends AbstractExtension
{
    public function __construct(
        private readonly FeatureToggle $features,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('feature_enabled', fn (string $feature): bool => $this->features->isEnabled(Feature::from($feature))),
            // Para pantallas de un centro que no tiene por qué ser el seleccionado (p. ej. el de un administrador global).
            new TwigFunction('feature_enabled_for', fn (string $feature, EducationalCentre $centre): bool => $this->features->isEnabledForCentre(Feature::from($feature), $centre)),
        ];
    }
}
