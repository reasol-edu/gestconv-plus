<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Entity\PushSubscription;
use App\Entity\StudentExit;
use App\Entity\Teacher;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Avisos push a los dispositivos de un docente. Suscribirse en un dispositivo es el consentimiento: quien tiene
 * suscripciones recibe los tres avisos (salida abierta 30 min, tareas de sanción y partes próximos a prescribir).
 * Sin claves VAPID no hace nada. Retira las suscripciones que el servicio de push da por caducadas.
 */
final class PushNotifier
{
    /** Minutos fuera a partir de los cuales se avisa, una sola vez, de una salida sin regreso. */
    public const LONG_EXIT_MINUTES = 30;

    public function __construct(
        private readonly PushDispatcherInterface $dispatcher,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public function isAvailable(): bool
    {
        return $this->dispatcher->isConfigured();
    }

    public function longExit(StudentExit $exit): void
    {
        $student = $exit->getStudent()->getName();
        $this->notify([$exit->getAuthorizedBy()], 'push.long_exit.title', 'push.long_exit.body', [
            '%student%' => $student->getFirstName() . ' ' . $student->getLastName(),
            '%minutes%' => self::LONG_EXIT_MINUTES,
        ], $this->urls->generate('app_exits_index'), 'exit-' . $exit->getId()->toRfc4122());
    }

    public function sanctionTasks(Teacher $teacher, int $count): void
    {
        $this->notify([$teacher], 'push.sanction_tasks.title', 'push.sanction_tasks.body', ['%count%' => $count],
            $this->urls->generate('app_sanction_tasks_index'), 'sanction-tasks');
    }

    public function reportsNearingPrescription(Teacher $teacher, int $count): void
    {
        $this->notify([$teacher], 'push.prescription.title', 'push.prescription.body', ['%count%' => $count],
            $this->urls->generate('app_notifications_index'), 'prescription');
    }

    /** Aviso de prueba desde el perfil, para comprobar que el dispositivo recibe notificaciones. */
    public function test(Teacher $teacher): void
    {
        $this->notify([$teacher], 'push.test.title', 'push.test.body', [], $this->urls->generate('app_dashboard'), 'test');
    }

    /**
     * @param list<Teacher>        $teachers
     * @param array<string, mixed> $params
     */
    private function notify(array $teachers, string $titleKey, string $bodyKey, array $params, string $url, string $tag): void
    {
        if (!$this->dispatcher->isConfigured()) {
            return;
        }

        $subscriptions = [];
        foreach ($this->subscriptions->findByTeachers($teachers) as $list) {
            array_push($subscriptions, ...$list);
        }
        if ($subscriptions === []) {
            return;
        }

        $payload = json_encode([
            'title' => $this->translator->trans($titleKey, $params, 'push'),
            'body'  => $this->translator->trans($bodyKey, $params, 'push'),
            'url'   => $url,
            'tag'   => $tag,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $expired = $this->dispatcher->dispatch($subscriptions, $payload);
        if ($expired === []) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            if ($this->isExpired($subscription, $expired)) {
                $this->em->remove($subscription);
            }
        }
        $this->em->flush();
    }

    /** @param list<string> $expiredEndpoints */
    private function isExpired(PushSubscription $subscription, array $expiredEndpoints): bool
    {
        return in_array($subscription->getEndpoint(), $expiredEndpoints, true);
    }
}
