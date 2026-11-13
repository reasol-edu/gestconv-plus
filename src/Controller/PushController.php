<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Entity\Teacher;
use App\Repository\PushSubscriptionRepository;
use App\Service\ActivityLogService;
use App\Service\Push\PushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Alta y baja de dispositivos para los avisos push, y aviso de prueba. Solo existe si hay claves VAPID.
 *
 * El servidor envía una petición HTTP a la dirección (`endpoint`) que le da el navegador, así que se valida con
 * una lista cerrada de servicios de push conocidos y HTTPS: de lo contrario, cualquier docente podría hacer que
 * el servidor llamara a direcciones internas (SSRF).
 */
#[IsGranted('ROLE_TEACHER')]
#[Route('/push')]
class PushController extends AbstractController
{
    /** Sufijos de host de los servicios de push de los navegadores (FCM, Mozilla, Apple, Windows). */
    private const ALLOWED_HOST_SUFFIXES = [
        '.googleapis.com',
        '.push.services.mozilla.com',
        '.push.apple.com',
        '.notify.windows.com',
    ];

    public const CSRF_ID = 'push_subscription';

    public function __construct(
        private readonly PushNotifier $push,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly ActivityLogService $activityLog,
    ) {}

    #[Route('/suscripcion', name: 'app_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        $user = $this->guard($request);

        $data     = $this->json_body($request);
        $endpoint = is_string($data['endpoint'] ?? null) ? $data['endpoint'] : '';
        $keys     = is_array($data['keys'] ?? null) ? $data['keys'] : [];
        $p256dh   = is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : '';
        $auth     = is_string($keys['auth'] ?? null) ? $keys['auth'] : '';

        if (!self::isAllowedEndpoint($endpoint) || !self::isBase64Url($p256dh) || !self::isBase64Url($auth)) {
            return $this->json(['error' => 'invalid_subscription'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $existing = $this->subscriptions->findByEndpoint($endpoint);
        if ($existing !== null) {
            $existing->reassignTo($user, $p256dh, $auth);
        } else {
            $this->em->persist(new PushSubscription($user, $endpoint, $p256dh, $auth, $request->headers->get('User-Agent')));
        }
        $this->em->flush();
        $this->activityLog->log('push.subscribed');

        return $this->json(['ok' => true], JsonResponse::HTTP_CREATED);
    }

    #[Route('/suscripcion', name: 'app_push_unsubscribe', methods: ['DELETE'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        $user = $this->guard($request);

        $data         = $this->json_body($request);
        $endpoint     = is_string($data['endpoint'] ?? null) ? $data['endpoint'] : '';
        $subscription = $endpoint !== '' ? $this->subscriptions->findByEndpoint($endpoint) : null;

        // Solo se puede dar de baja un dispositivo propio.
        if ($subscription !== null && $subscription->getTeacher()->getId()->equals($user->getId())) {
            $this->em->remove($subscription);
            $this->em->flush();
            $this->activityLog->log('push.unsubscribed');
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/prueba', name: 'app_push_test', methods: ['POST'])]
    public function test(Request $request): JsonResponse
    {
        $user = $this->guard($request);
        $this->push->test($user);

        return $this->json(['ok' => true]);
    }

    private function guard(Request $request): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher || !$this->push->isAvailable()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->headers->get('X-CSRF-Token', ''))) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function json_body(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = is_array($decoded) ? $decoded : [];

        return $result;
    }

    public static function isAllowedEndpoint(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > 2048) {
            return false;
        }

        $parts = parse_url($endpoint);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function isBase64Url(string $value): bool
    {
        return $value !== '' && strlen($value) <= 255 && preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) === 1;
    }
}
