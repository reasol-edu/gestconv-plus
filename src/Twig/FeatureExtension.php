<?php

declare(strict_types=1);

namespace App\Twig;

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
        ];
    }
}
