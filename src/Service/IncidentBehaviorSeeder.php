<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use Doctrine\ORM\EntityManagerInterface;

final class IncidentBehaviorSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function seedForCentre(EducationalCentre $centre): void
    {
        $behaviors = [
            // name, serious
            ['Perturbación del normal desarrollo de las actividades de clase', false],
            ['Falta de colaboración sistemática en la realización de las actividades', false],
            ['Impedir o dificultar el estudio a sus compañeros', false],
            ['Faltas injustificadas de puntualidad', false],
            ['Faltas injustificadas de asistencia a clase', false],
            ['Actuaciones incorrectas hacia algún miembro de la comunidad educativa', false],
            ['Daños en instalaciones o docum. del Centro o en pertenencias de un miembro', false],
            ['Agresión física a un miembro de la comunidad educativa', true],
            ['Injurias y ofensas contra un miembro de la comunidad educativa', true],
            ['Acoso escolar', true],
            ['Actuaciones perjudiciales para la salud y la integridad, o incitación a ellas', true],
            ['Vejaciones o humillaciones contra un miembro de la comunidad educativa', true],
            ['Amenazas o coacciones a un miembro de la comunidad educativa', true],
            ['Suplantación de la personalidad, y falsificación o sustracción de documentos', true],
            ['Deterioro grave de instalac. o docum. del Centro, o pertenencias de un miembro', true],
            ['Reiteración en un mismo curso de conductas contrarias a normas de convivencia', true],
            ['Impedir el normal desarrollo de las actividades del centro', true],
            ['Incumplimiento de las correcciones impuestas', true],
            ['Las descritas con detalle más abajo', false],
        ];

        foreach ($behaviors as $position => [$name, $serious]) {
            $behavior = (new IncidentBehavior())
                ->setEducationalCentre($centre)
                ->setName($name)
                ->setPosition($position)
                ->setSerious($serious)
                ->setActive(true);

            $this->em->persist($behavior);
        }
    }
}
