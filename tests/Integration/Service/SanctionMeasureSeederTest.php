<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\SanctionMeasureCategoryRepository;
use App\Repository\SanctionMeasureRepository;
use App\Service\SanctionMeasureSeeder;
use App\Tests\Integration\RepositoryTestCase;

class SanctionMeasureSeederTest extends RepositoryTestCase
{
    private SanctionMeasureSeeder $seeder;
    private SanctionMeasureRepository $repo;
    private SanctionMeasureCategoryRepository $categoryRepo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SanctionMeasureSeeder $seeder */
        $seeder       = self::getContainer()->get(SanctionMeasureSeeder::class);
        $this->seeder = $seeder;

        /** @var SanctionMeasureRepository $repo */
        $repo       = self::getContainer()->get(SanctionMeasureRepository::class);
        $this->repo = $repo;

        /** @var SanctionMeasureCategoryRepository $categoryRepo */
        $categoryRepo       = self::getContainer()->get(SanctionMeasureCategoryRepository::class);
        $this->categoryRepo = $categoryRepo;
    }

    public function testSeedCreates3Categories(): void
    {
        $centre = $this->makeCentre('41200001');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        self::assertCount(3, $this->categoryRepo->findByCentreOrdered($centre));
    }

    public function testSeedCreates15Measures(): void
    {
        $centre = $this->makeCentre('41200002');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        self::assertCount(15, $this->repo->findByCentreOrdered($centre));
    }

    public function testAllMeasuresAreActiveByDefault(): void
    {
        $centre = $this->makeCentre('41200003');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        foreach ($this->repo->findByCentreOrdered($centre) as $measure) {
            self::assertTrue($measure->isActive(), "Measure «{$measure->getName()}» should be active");
        }
    }

    public function testExactlyThreeMeasuresHaveDateRange(): void
    {
        $centre = $this->makeCentre('41200004');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $withDateRange = array_filter(
            $this->repo->findByCentreOrdered($centre),
            static fn(\App\Entity\SanctionMeasure $m): bool => $m->hasDateRange()
        );
        self::assertCount(3, $withDateRange);
    }

    public function testFirstCategoryContainsConductasContrarias(): void
    {
        $centre = $this->makeCentre('41200005');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        self::assertStringContainsString('contrarias', strtolower($categories[0]->getName()));
    }

    public function testSecondCategoryContainsConductasGravementePerjudiciales(): void
    {
        $centre = $this->makeCentre('41200006');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        self::assertStringContainsString('gravemente', strtolower($categories[1]->getName()));
    }

    public function testThirdCategoryIsOtrasMedidas(): void
    {
        $centre = $this->makeCentre('41200007');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        self::assertStringContainsString('otras', strtolower($categories[2]->getName()));
    }

    public function testMeasuresAreOrderedWithinTheirCategory(): void
    {
        $centre = $this->makeCentre('41200008');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        foreach ($this->categoryRepo->findByCentreOrdered($centre) as $cat) {
            $measures = $this->repo->findByCategoryOrdered($cat);
            for ($i = 0; $i < count($measures) - 1; $i++) {
                self::assertLessThanOrEqual(
                    $measures[$i + 1]->getPosition(),
                    $measures[$i]->getPosition(),
                );
            }
        }
    }

    public function testDateRangeMeasuresAreOnlyInFirstTwoCategories(): void
    {
        $centre = $this->makeCentre('41200009');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);

        // Category 3 (Otras medidas) should have no date-range measures
        foreach ($this->repo->findByCategoryOrdered($categories[2]) as $measure) {
            self::assertFalse($measure->hasDateRange(), "Category 3 measure «{$measure->getName()}» should not require dates");
        }
    }

    public function testSeedingTwoCentresDoesNotMixData(): void
    {
        $centreA = $this->makeCentre('41200010');
        $centreB = $this->makeCentre('41200011');
        $this->persist($centreA, $centreB);

        $this->seeder->seedForCentre($centreA);
        $this->seeder->seedForCentre($centreB);
        $this->flush();

        self::assertCount(15, $this->repo->findByCentreOrdered($centreA));
        self::assertCount(15, $this->repo->findByCentreOrdered($centreB));
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
