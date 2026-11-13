<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\AppSettingsInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Horas que se conserva en el dispositivo el borrador de un parte (ajuste global de la plataforma;
 * 0 = no se guardan borradores).
 */
final class DraftRetentionExtension extends AbstractExtension
{
    public const string SETTING = 'display.draft_retention_hours';

    private const int DEFAULT_HOURS = 24;

    public function __construct(
        private readonly AppSettingsInterface $settings,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('draft_retention_hours', $this->hours(...)),
        ];
    }

    public function hours(): int
    {
        $value = $this->settings->getGlobal(self::SETTING);

        // Sin definición (base sin migrar) se usa el plazo por defecto en vez de desactivar los borradores.
        return is_int($value) ? max(0, $value) : self::DEFAULT_HOURS;
    }
}
