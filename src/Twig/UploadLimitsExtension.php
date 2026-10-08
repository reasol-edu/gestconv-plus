<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\UploadLimits;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Expone a las plantillas los límites de subida reales (los de la aplicación acotados por los de PHP). */
final class UploadLimitsExtension extends AbstractExtension
{
    public function __construct(
        private readonly UploadLimits $limits,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('upload_limits', fn (): UploadLimits => $this->limits),
        ];
    }
}
