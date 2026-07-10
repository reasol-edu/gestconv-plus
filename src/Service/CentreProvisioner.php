<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a new educational centre with its first academic year and seeds
 * the catalogs (conductas, sanciones, métodos de comunicación) it needs to
 * be usable right away.
 *
 * Shared by app:setup, app:create-educational-centre and AppFixtures, which
 * previously duplicated this same sequence.
 */
final class CentreProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IncidentBehaviorSeeder $behaviorSeeder,
        private readonly SanctionMeasureSeeder $sanctionMeasureSeeder,
        private readonly CommunicationMethodSeeder $communicationMethodSeeder,
        private readonly LocationOptionSeeder $locationOptionSeeder,
    ) {}

    public function provision(string $code, string $name, string $city, string $academicYearName): EducationalCentre
    {
        $centre = (new EducationalCentre())
            ->setCode($code)
            ->setName($name)
            ->setCity($city);

        $academicYear = (new AcademicYear())
            ->setName($academicYearName)
            ->setEducationalCentre($centre);

        $centre->setActiveAcademicYear($academicYear);

        $this->em->persist($centre);
        $this->em->persist($academicYear);

        $this->behaviorSeeder->seedForCentre($centre);
        $this->sanctionMeasureSeeder->seedForCentre($centre);
        $this->communicationMethodSeeder->seedForCentre($centre);
        $this->locationOptionSeeder->seedForCentre($centre);

        $this->em->flush();

        return $centre;
    }
}
