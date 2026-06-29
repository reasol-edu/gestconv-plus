<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\IncidentBehaviorRepository;
use App\Service\IncidentBehaviorSeeder;
use App\Tests\Integration\RepositoryTestCase;

class IncidentBehaviorSeederTest extends RepositoryTestCase
{
    private IncidentBehaviorSeeder $seeder;
    private IncidentBehaviorRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var IncidentBehaviorSeeder $seeder */
        $seeder       = self::getContainer()->get(IncidentBehaviorSeeder::class);
        $this->seeder = $seeder;

        /** @var IncidentBehaviorRepository $repo */
        $repo       = self::getContainer()->get(IncidentBehaviorRepository::class);
        $this->repo = $repo;
    }

    public function testSeedCreates19Behaviors(): void
    {
        $centre = $this->makeCentre('41100001');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $behaviors = $this->repo->findByCentreOrdered($centre);

        self::assertCount(19, $behaviors);
    }

    public function testAllBehaviorsAreActiveByDefault(): void
    {
        $centre = $this->makeCentre('41100002');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        foreach ($this->repo->findByCentreOrdered($centre) as $behavior) {
            self::assertTrue($behavior->isActive(), "Behavior «{$behavior->getName()}» should be active");
        }
    }

    public function testFirst7BehaviorsAreNotSerious(): void
    {
        $centre = $this->makeCentre('41100003');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $behaviors = $this->repo->findByCentreOrdered($centre);

        for ($i = 0; $i < 7; $i++) {
            self::assertFalse(
                $behaviors[$i]->isSerious(),
                "Behavior at position {$i} («{$behaviors[$i]->getName()}») should not be serious"
            );
        }
    }

    public function testBehaviors8To18AreSerious(): void
    {
        $centre = $this->makeCentre('41100004');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $behaviors = $this->repo->findByCentreOrdered($centre);

        for ($i = 7; $i <= 17; $i++) {
            self::assertTrue(
                $behaviors[$i]->isSerious(),
                "Behavior at position {$i} («{$behaviors[$i]->getName()}») should be serious"
            );
        }
    }

    public function testLastBehaviorIsNotSeriousCatchAll(): void
    {
        $centre = $this->makeCentre('41100005');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $behaviors = $this->repo->findByCentreOrdered($centre);
        $last      = $behaviors[18];

        self::assertFalse($last->isSerious());
        self::assertStringContainsString('descritas', $last->getName());
    }

    public function testBehaviorsAreOrderedByPosition(): void
    {
        $centre = $this->makeCentre('41100006');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $behaviors = $this->repo->findByCentreOrdered($centre);

        for ($i = 0; $i < count($behaviors) - 1; $i++) {
            self::assertLessThanOrEqual(
                $behaviors[$i + 1]->getPosition(),
                $behaviors[$i]->getPosition(),
            );
        }
    }

    public function testSeedingTwoCentresDoesNotMixBehaviors(): void
    {
        $centreA = $this->makeCentre('41100007');
        $centreB = $this->makeCentre('41100008');
        $this->persist($centreA, $centreB);

        $this->seeder->seedForCentre($centreA);
        $this->seeder->seedForCentre($centreB);
        $this->flush();

        self::assertCount(19, $this->repo->findByCentreOrdered($centreA));
        self::assertCount(19, $this->repo->findByCentreOrdered($centreB));
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
