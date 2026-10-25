<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Group;
use App\Service\GroupColorPalette;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `group_color(group)`: colores del grupo (el personalizado o el calculado) para pintar lo que se relaciona con él. */
final class GroupColorExtension extends AbstractExtension
{
    public function __construct(
        private readonly GroupColorPalette $palette,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('group_color', $this->palette->forGroup(...)),
            new TwigFunction('group_default_color', fn (Group $group): string => $this->palette->defaultHex($group->getId()->toRfc4122())),
        ];
    }
}
