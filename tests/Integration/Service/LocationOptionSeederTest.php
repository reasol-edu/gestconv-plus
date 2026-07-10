<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Repository\LocationOptionCategoryRepository;
use App\Repository\LocationOptionRepository;
use App\Service\LocationOptionSeeder;
use App\Tests\Integration\RepositoryTestCase;

class LocationOptionSeederTest extends RepositoryTestCase
{
    private LocationOptionSeeder $seeder;
    private LocationOptionRepository $repo;
    private LocationOptionCategoryRepository $categoryRepo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var LocationOptionSeeder $seeder */
        $seeder       = self::getContainer()->get(LocationOptionSeeder::class);
        $this->seeder = $seeder;

        /** @var LocationOptionRepository $repo */
        $repo       = self::getContainer()->get(LocationOptionRepository::class);
        $this->repo = $repo;

        /** @var LocationOptionCategoryRepository $categoryRepo */
        $categoryRepo       = self::getContainer()->get(LocationOptionCategoryRepository::class);
        $this->categoryRepo = $categoryRepo;
    }

    public function testSeedCreates1Category(): void
    {
        $centre = $this->makeCentre('41200001');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        self::assertCount(1, $categories);
        self::assertSame('General', $categories[0]->getName());
    }

    public function testSeedCreates6Options(): void
    {
        $centre = $this->makeCentre('41200002');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $options = $this->repo->findByCentreOrdered($centre);
        self::assertCount(6, $options);
    }

    public function testAllOptionsAreActiveByDefault(): void
    {
        $centre = $this->makeCentre('41200003');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        foreach ($this->repo->findByCentreOrdered($centre) as $option) {
            self::assertTrue($option->isActive(), "Location option «{$option->getName()}» should be active");
        }
    }

    public function testOptionsContainExpectedDefaults(): void
    {
        $centre = $this->makeCentre('41200004');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $names = array_map(static fn ($o) => $o->getName(), $this->repo->findByCentreOrdered($centre));
        self::assertSame([
            'En clase',
            'En el intercambio de clases',
            'Entrada/Salida',
            'Recreo',
            'Durante las actividades extraescolares',
            'Otros',
        ], $names);
    }

    public function testOptionsAreOrderedWithinCategory(): void
    {
        $centre = $this->makeCentre('41200005');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $categories = $this->categoryRepo->findByCentreOrdered($centre);
        $options    = $this->repo->findByCategoryOrdered($categories[0]);
        for ($i = 0; $i < count($options) - 1; $i++) {
            self::assertLessThanOrEqual(
                $options[$i + 1]->getPosition(),
                $options[$i]->getPosition(),
            );
        }
    }

    public function testSeedingTwoCentresDoesNotMixData(): void
    {
        $centreA = $this->makeCentre('41200006');
        $centreB = $this->makeCentre('41200007');
        $this->persist($centreA, $centreB);

        $this->seeder->seedForCentre($centreA);
        $this->seeder->seedForCentre($centreB);
        $this->flush();

        self::assertCount(6, $this->repo->findByCentreOrdered($centreA));
        self::assertCount(6, $this->repo->findByCentreOrdered($centreB));
        self::assertCount(1, $this->categoryRepo->findByCentreOrdered($centreA));
        self::assertCount(1, $this->categoryRepo->findByCentreOrdered($centreB));
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
