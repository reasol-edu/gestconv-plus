<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\CommunicationMethodRepository;
use App\Service\CommunicationMethodSeeder;
use App\Tests\Integration\RepositoryTestCase;

class CommunicationMethodSeederTest extends RepositoryTestCase
{
    private CommunicationMethodSeeder $seeder;
    private CommunicationMethodRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CommunicationMethodSeeder $seeder */
        $seeder       = self::getContainer()->get(CommunicationMethodSeeder::class);
        $this->seeder = $seeder;

        /** @var CommunicationMethodRepository $repo */
        $repo       = self::getContainer()->get(CommunicationMethodRepository::class);
        $this->repo = $repo;
    }

    public function testSeedCreates6Methods(): void
    {
        $centre = $this->makeCentre('41200001');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $methods = $this->repo->findByCentreOrdered($centre);
        self::assertCount(6, $methods);
    }

    public function testAllMethodsAreActiveByDefault(): void
    {
        $centre = $this->makeCentre('41200002');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        foreach ($this->repo->findByCentreOrdered($centre) as $method) {
            self::assertTrue($method->isActive(), "Method «{$method->getName()}» should be active");
        }
    }

    public function testMethodsAreOrderedByPosition(): void
    {
        $centre = $this->makeCentre('41200003');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $methods = $this->repo->findByCentreOrdered($centre);
        for ($i = 0; $i < count($methods) - 1; $i++) {
            self::assertLessThan($methods[$i + 1]->getPosition(), $methods[$i]->getPosition());
        }
    }

    public function testFirstMethodMatchesConfig(): void
    {
        $centre = $this->makeCentre('41200004');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $methods = $this->repo->findByCentreOrdered($centre);
        self::assertSame('Llamada telefónica', $methods[0]->getName());
    }

    public function testSeedingTwoCentresDoesNotMixData(): void
    {
        $centreA = $this->makeCentre('41200005');
        $centreB = $this->makeCentre('41200006');
        $this->persist($centreA, $centreB);

        $this->seeder->seedForCentre($centreA);
        $this->seeder->seedForCentre($centreB);
        $this->flush();

        self::assertCount(6, $this->repo->findByCentreOrdered($centreA));
        self::assertCount(6, $this->repo->findByCentreOrdered($centreB));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeCentre(string $code): EducationalCentre
    {
        $centre = (new EducationalCentre())->setCode($code)->setName('IES ' . $code)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $this->em->persist($year);

        return $centre;
    }
}
