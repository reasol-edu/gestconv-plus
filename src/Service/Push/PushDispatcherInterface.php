<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Entity\PushSubscription;

/** Envío de una notificación Web Push ya serializada a un conjunto de suscripciones. */
interface PushDispatcherInterface
{
    /** Hay claves VAPID configuradas: sin ellas la función de avisos push no existe. */
    public function isConfigured(): bool;

    /** Clave pública VAPID que el navegador necesita para suscribirse, o null si no hay claves. */
    public function publicKey(): ?string;

    /**
     * @param list<PushSubscription> $subscriptions
     * @return list<string> endpoints que el servicio de push da por caducados (hay que retirar esas suscripciones)
     */
    public function dispatch(array $subscriptions, string $payload): array;
}
