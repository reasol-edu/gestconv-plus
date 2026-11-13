<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\NotifyLongExitsMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\StudentExitRepository;
use App\Service\Feature;
use App\Service\FeatureToggle;
use App\Service\Push\PushNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Avisa por push, una sola vez, al docente que autorizó una salida del aula cuando el estudiante lleva
 * `PushNotifier::LONG_EXIT_MINUTES` minutos fuera sin regreso. Solo salidas de hoy y de centros con la sección
 * Salidas activada; sin claves VAPID no hace nada (ni siquiera marca las salidas).
 */
#[AsMessageHandler]
final class NotifyLongExitsHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly StudentExitRepository $exits,
        private readonly FeatureToggle $features,
        private readonly PushNotifier $push,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(NotifyLongExitsMessage $message): void
    {
        if (!$this->push->isAvailable()) {
            return;
        }

        $now          = $this->clock->now();
        $startOfToday = $now->setTime(0, 0);
        $threshold    = $now->modify('-' . PushNotifier::LONG_EXIT_MINUTES . ' minutes');

        foreach ($this->centres->findAll() as $centre) {
            if (!$this->features->isEnabledForCentre(Feature::Exits, $centre)) {
                continue;
            }

            foreach ($this->exits->findOutLongerThan($centre, $startOfToday, $threshold) as $exit) {
                $this->push->longExit($exit);
                $exit->markLongNoticeSent($now);
            }
        }

        $this->em->flush();
    }
}
