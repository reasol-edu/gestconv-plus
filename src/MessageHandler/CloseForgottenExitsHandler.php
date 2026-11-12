<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CloseForgottenExitsMessage;
use App\Repository\EducationalCentreRepository;
use App\Repository\StudentExitRepository;
use App\Service\Feature;
use App\Service\FeatureToggle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cierra las salidas del aula de días anteriores que nadie marcó como regresadas. Sin esto, un
 * estudiante cuya salida se olvidó seguiría «fuera» y no podría volver a salir al día siguiente.
 *
 * No inventa un regreso: anota `autoClosedAt` y deja `returnedAt` vacío, así que esas salidas no
 * cuentan en los tiempos fuera de los informes. «Anterior a hoy» se mide en la zona horaria de la
 * aplicación. Los centros con la sección Salidas desactivada se saltan.
 */
#[AsMessageHandler]
final class CloseForgottenExitsHandler
{
    public function __construct(
        private readonly EducationalCentreRepository $centres,
        private readonly StudentExitRepository $exits,
        private readonly FeatureToggle $features,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {}

    public function __invoke(CloseForgottenExitsMessage $message): void
    {
        $now          = $this->clock->now();
        $startOfToday = $now->setTime(0, 0);

        foreach ($this->centres->findAll() as $centre) {
            if (!$this->features->isEnabledForCentre(Feature::Exits, $centre)) {
                continue;
            }

            foreach ($this->exits->findOutBefore($centre, $startOfToday) as $exit) {
                $exit->markAutoClosed($now);
            }
        }

        $this->em->flush();
    }
}
