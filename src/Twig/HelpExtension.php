<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\HelpLinks;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `help_url(route)`: dirección del apartado del manual que explica la pantalla actual, o null. */
final class HelpExtension extends AbstractExtension
{
    public function __construct(
        private readonly HelpLinks $help,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('help_url', fn (?string $route): ?string => $this->help->urlForRoute($route)),
        ];
    }
}
