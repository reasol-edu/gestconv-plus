<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\ExitReasonRepository;
use App\Service\ExitReasonSeeder;
use App\Tests\Integration\RepositoryTestCase;

class ExitReasonSeederTest extends RepositoryTestCase
{
    private ExitReasonSeeder $seeder;
    private ExitReasonRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ExitReasonSeeder $seeder */
        $seeder       = self::getContainer()->get(ExitReasonSeeder::class);
        $this->seeder = $seeder;

        /** @var ExitReasonRepository $repo */
        $repo       = self::getContainer()->get(ExitReasonRepository::class);
        $this->repo = $repo;
    }

    public function testSeedCreatesTheDefaultReasonsInOrderAndActive(): void
    {
        $centre = $this->makeCentre('41200901');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $reasons = $this->repo->findByCentreOrdered($centre);
        self::assertSame(
            ['Servicio', 'Fuente', 'Conserjería', 'Departamentos', 'Carro portátiles', 'Secretaría', 'Jefat. de Estudios', 'Dirección', 'Otros'],
            array_map(static fn ($r): string => $r->getName(), $reasons),
        );
        foreach ($reasons as $reason) {
            self::assertTrue($reason->isActive());
        }
    }

    public function testSeedingOneCentreDoesNotTouchAnother(): void
    {
        $a = $this->makeCentre('41200902');
        $b = $this->makeCentre('41200903');
        $this->persist($a, $b);

        $this->seeder->seedForCentre($a);
        $this->flush();

        self::assertCount(9, $this->repo->findByCentreOrdered($a));
        self::assertCount(0, $this->repo->findByCentreOrdered($b));
    }

    private function makeCentre(string $code): EducationalCentre
    {
        $centre = (new EducationalCentre())->setCode($code)->setName('IES ' . $code)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $this->em->persist($year);

        return $centre;
    }
}
