<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\IncidentBehaviorCategoryRepository;
use App\Repository\IncidentBehaviorRepository;
use App\Service\IncidentBehaviorSeeder;
use App\Tests\Integration\RepositoryTestCase;

class IncidentBehaviorSeederTest extends RepositoryTestCase
{
    private IncidentBehaviorSeeder $seeder;
    private IncidentBehaviorRepository $repo;
    private IncidentBehaviorCategoryRepository $categoryRepo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var IncidentBehaviorSeeder $seeder */
        $seeder       = self::getContainer()->get(IncidentBehaviorSeeder::class);
        $this->seeder = $seeder;

        /** @var IncidentBehaviorRepository $repo */
        $repo       = self::getContainer()->get(IncidentBehaviorRepository::class);
        $this->repo = $repo;

        /** @var IncidentBehaviorCategoryRepository $categoryRepo */
        $categoryRepo       = self::getContainer()->get(IncidentBehaviorCategoryRepository::class);
        $this->categoryRepo = $categoryRepo;
    }

    public function testSeedCreates3Categories(): void
    {
        $centre = $this->makeCentre('41100001');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        self::assertCount(3, $categories);
    }

    public function testSeedCreates19Behaviors(): void
    {
        $centre = $this->makeCentre('41100002');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $behaviors = $this->repo->findByCentreOrdered($centre);
        self::assertCount(19, $behaviors);
    }

    public function testAllBehaviorsAreActiveByDefault(): void
    {
        $centre = $this->makeCentre('41100003');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        foreach ($this->repo->findByCentreOrdered($centre) as $behavior) {
            self::assertTrue($behavior->isActive(), "Behavior «{$behavior->getName()}» should be active");
        }
    }

    public function testFirstCategoryIsNotSerious(): void
    {
        $centre = $this->makeCentre('41100004');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        self::assertFalse($categories[0]->isSerious(), 'First category should not be serious');
        self::assertStringContainsString('contrarias', strtolower($categories[0]->getName()));
    }

    public function testSecondCategoryIsSerious(): void
    {
        $centre = $this->makeCentre('41100005');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        self::assertTrue($categories[1]->isSerious(), 'Second category should be serious');
        self::assertStringContainsString('gravemente', strtolower($categories[1]->getName()));
    }

    public function testThirdCategoryIsNotSeriousAndContainsCatchAll(): void
    {
        $centre = $this->makeCentre('41100006');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        self::assertFalse($categories[2]->isSerious(), 'Third category should not be serious');

        $behaviors = $this->repo->findByCategoryOrdered($categories[2]);
        self::assertCount(1, $behaviors);
        self::assertStringContainsString('descritas', $behaviors[0]->getName());
    }

    public function testBehaviorsAreOrderedWithinCategories(): void
    {
        $centre = $this->makeCentre('41100007');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        foreach ($categories as $cat) {
            $behaviors = $this->repo->findByCategoryOrdered($cat);
            for ($i = 0; $i < count($behaviors) - 1; $i++) {
                self::assertLessThanOrEqual(
                    $behaviors[$i + 1]->getPosition(),
                    $behaviors[$i]->getPosition(),
                );
            }
        }
    }

    public function testBehaviorInheritsSeriosnessFromCategory(): void
    {
        $centre = $this->makeCentre('41100008');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        foreach ($categories as $cat) {
            foreach ($this->repo->findByCategoryOrdered($cat) as $beh) {
                self::assertSame(
                    $cat->isSerious(),
                    $beh->isSerious(),
                    "Behavior «{$beh->getName()}» seriousness should match its category"
                );
            }
        }
    }

    public function testSeedingTwoCentresDoesNotMixData(): void
    {
        $centreA = $this->makeCentre('41100009');
        $centreB = $this->makeCentre('41100010');
        $this->persist($centreA, $centreB);

        $this->seeder->seedForCentre($centreA);
        $this->seeder->seedForCentre($centreB);
        $this->flush();

        self::assertCount(19, $this->repo->findByCentreOrdered($centreA));
        self::assertCount(19, $this->repo->findByCentreOrdered($centreB));
        self::assertCount(3, $this->categoryRepo->findByCentreOrdered($centreA));
        self::assertCount(3, $this->categoryRepo->findByCentreOrdered($centreB));
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
