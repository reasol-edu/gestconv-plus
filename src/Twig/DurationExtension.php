<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\DurationFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** Filtro `report_duration`: una duración en segundos en texto legible, para los informes. */
final class DurationExtension extends AbstractExtension
{
    public function __construct(
        private readonly DurationFormatter $formatter,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('report_duration', $this->formatter->format(...)),
        ];
    }
}
