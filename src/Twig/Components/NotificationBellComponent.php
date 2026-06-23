<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class NotificationBellComponent extends AbstractController
{
    use DefaultActionTrait;

    public const MAX_ITEMS = 8;

    /** @return list<mixed> */
    public function getItems(): array
    {
        return [];
    }

    public function getTotal(): int
    {
        return 0;
    }

    /** @return list<mixed> */
    public function getVisibleItems(): array
    {
        return [];
    }
}
