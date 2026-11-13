<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Entity\PushSubscription;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Envío real por Web Push con claves VAPID (variables de entorno VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY y
 * VAPID_SUBJECT). Sin las dos claves, `isConfigured()` es falso y no se envía nada.
 */
final class WebPushDispatcher implements PushDispatcherInterface
{
    public function __construct(
        #[Autowire('%env(default::VAPID_PUBLIC_KEY)%')]
        private readonly ?string $publicKey,
        #[Autowire('%env(default::VAPID_PRIVATE_KEY)%')]
        private readonly ?string $privateKey,
        #[Autowire('%env(default::VAPID_SUBJECT)%')]
        private readonly ?string $subject,
        private readonly LoggerInterface $logger,
    ) {}

    public function isConfigured(): bool
    {
        return $this->publicKey !== null && $this->publicKey !== '' && $this->privateKey !== null && $this->privateKey !== '';
    }

    public function publicKey(): ?string
    {
        return $this->isConfigured() ? $this->publicKey : null;
    }

    public function dispatch(array $subscriptions, string $payload): array
    {
        if (!$this->isConfigured() || $subscriptions === []) {
            return [];
        }

        $expired = [];
        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => $this->subject !== null && $this->subject !== '' ? $this->subject : 'mailto:no-responder@example.com',
                    'publicKey'  => (string) $this->publicKey,
                    'privateKey' => (string) $this->privateKey,
                ],
            ], ['TTL' => 3600], new Psr18Client(HttpClient::create(['timeout' => 10, 'max_redirects' => 0])));

            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint'  => $subscription->getEndpoint(),
                    'publicKey' => $subscription->getP256dh(),
                    'authToken' => $subscription->getAuthToken(),
                ]), $payload);
            }

            /** @var iterable<MessageSentReport> $reports */
            $reports = $webPush->flush();
            foreach ($reports as $report) {
                if ($report->isSubscriptionExpired()) {
                    $expired[] = $report->getEndpoint();
                } elseif (!$report->isSuccess()) {
                    $this->logger->warning('Aviso push no entregado: {reason}', ['reason' => $report->getReason()]);
                }
            }
        } catch (\Throwable $e) {
            // Sin salida a internet o claves inválidas: un aviso que no sale nunca debe romper la tarea que lo origina.
            $this->logger->warning('No se han podido enviar avisos push: {message}', ['message' => $e->getMessage()]);
        }

        return $expired;
    }
}
