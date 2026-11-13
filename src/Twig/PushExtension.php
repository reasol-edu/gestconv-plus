<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Push\PushDispatcherInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `push_public_key()`: clave pública VAPID, o null si los avisos push no están configurados (la función no se ofrece). */
final class PushExtension extends AbstractExtension
{
    public function __construct(
        private readonly PushDispatcherInterface $dispatcher,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('push_public_key', fn (): ?string => $this->dispatcher->publicKey()),
        ];
    }
}
