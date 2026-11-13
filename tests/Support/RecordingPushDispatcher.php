<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\PushSubscription;
use App\Service\Push\PushDispatcherInterface;

/** Sustituye al envío real en los tests: no sale nada a la red y deja constancia de lo que se habría enviado. */
final class RecordingPushDispatcher implements PushDispatcherInterface
{
    public static bool $configured = false;

    /** @var list<array{endpoints: list<string>, payload: array<string, mixed>}> */
    public static array $sent = [];

    /** @var list<string> endpoints que el «servicio de push» responderá como caducados */
    public static array $expire = [];

    public static function reset(): void
    {
        self::$configured = false;
        self::$sent       = [];
        self::$expire     = [];
    }

    public function isConfigured(): bool
    {
        return self::$configured;
    }

    public function publicKey(): ?string
    {
        return self::$configured ? 'BClavePublicaDePrueba' : null;
    }

    public function dispatch(array $subscriptions, string $payload): array
    {
        $endpoints = array_map(static fn (PushSubscription $s): string => $s->getEndpoint(), $subscriptions);
        /** @var array<string, mixed> $decoded */
        $decoded      = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        self::$sent[] = ['endpoints' => $endpoints, 'payload' => $decoded];

        return array_values(array_intersect($endpoints, self::$expire));
    }
}
