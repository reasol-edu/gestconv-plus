<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\StudentExit;
use App\Service\ExitTiming;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Funciones de Twig para pintar el tiempo fuera de las salidas autorizadas.
 */
final class ExitTimingExtension extends AbstractExtension
{
    public function __construct(
        private readonly ExitTiming $timing,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('exit_seconds', fn (StudentExit $exit): int => $this->timing->seconds($exit)),
            new TwigFunction('exit_level', $this->timing->level(...)),
            new TwigFunction('exit_ago', $this->timing->ago(...)),
            new TwigFunction('exit_duration', $this->timing->duration(...)),
            new TwigFunction('exit_labels', fn (): string => (string) json_encode($this->timing->labels(), JSON_UNESCAPED_UNICODE)),
            new TwigFunction('exit_thresholds', fn (): string => (string) json_encode(ExitTiming::LEVEL_MINUTES)),
            new TwigFunction('exit_is_today', $this->timing->isToday(...)),
        ];
    }
}
