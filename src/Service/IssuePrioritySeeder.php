<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\IssuePriority;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prioridades de incidencia por defecto de un centro nuevo, de más a menos importante, con un degradado de verde a rojo
 * y el plazo máximo de resolución en días lectivos (0 = no procede). Los centros que ya existían las recibieron en la
 * migración que creó el módulo.
 */
final class IssuePrioritySeeder
{
    /** @var list<array{string, string, int}> nombre, color y plazo máximo */
    public const DEFAULTS = [
        ['Crítica', '#dc2626', 1],
        ['Alta', '#ea580c', 7],
        ['Media', '#ca8a04', 0],
        ['Baja', '#16a34a', 0],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function seedForCentre(EducationalCentre $centre): void
    {
        foreach (self::DEFAULTS as $position => [$name, $color, $maxDays]) {
            $this->em->persist(
                (new IssuePriority())
                    ->setEducationalCentre($centre)
                    ->setName($name)
                    ->setColor($color)
                    ->setPosition($position)
                    ->setRecommendedDays(0)
                    ->setMaxDays($maxDays),
            );
        }
    }
}
