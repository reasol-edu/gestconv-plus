<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Repository\DailyNoteTypeRepository;
use App\Service\DailyNoteTypeSeeder;
use App\Tests\Integration\RepositoryTestCase;

/** Tipos de nota que reciben los centros nuevos. */
class DailyNoteTypeSeederTest extends RepositoryTestCase
{
    private DailyNoteTypeSeeder $seeder;
    private DailyNoteTypeRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DailyNoteTypeSeeder $seeder */
        $seeder       = self::getContainer()->get(DailyNoteTypeSeeder::class);
        $this->seeder = $seeder;

        /** @var DailyNoteTypeRepository $repo */
        $repo       = self::getContainer()->get(DailyNoteTypeRepository::class);
        $this->repo = $repo;
    }

    public function testANewCentreGetsTheThreeDefaultTypesAndNoneAboutTheBathroom(): void
    {
        $centre = $this->makeCentre('41300901');
        $this->persist($centre);

        $this->seeder->seedForCentre($centre);
        $this->flush();

        $types = $this->repo->findByCentreOrdered($centre);
        self::assertSame(
            ['Uso del móvil', 'Retraso a primera hora', 'Otros'],
            array_map(static fn (DailyNoteType $t): string => $t->getName(), $types),
        );
        self::assertSame([2, 3, 0], array_map(static fn (DailyNoteType $t): int => $t->getOccurrencesForReport(), $types));
        self::assertSame([30, 0, 0], array_map(static fn (DailyNoteType $t): int => $t->getExpiryDays(), $types));
        // Las salidas al baño tienen su propia sección (Salidas): no se ofrecen como tipo de nota.
        foreach ($types as $type) {
            self::assertStringNotContainsStringIgnoringCase('baño', $type->getName());
        }
    }

    public function testSeedingANewCentreDoesNotTouchTheTypesOfAnExistingOne(): void
    {
        $existing = $this->makeCentre('41300902');
        $new      = $this->makeCentre('41300903');
        $this->persist($existing, $new);
        // Un centro anterior que ya tenía «Salida al baño» lo conserva: nada lo elimina.
        $this->persist((new DailyNoteType())->setEducationalCentre($existing)->setName('Salida al baño')->setPosition(0));

        $this->seeder->seedForCentre($new);
        $this->flush();

        self::assertSame(['Salida al baño'], array_map(static fn (DailyNoteType $t): string => $t->getName(), $this->repo->findByCentreOrdered($existing)));
        self::assertCount(3, $this->repo->findByCentreOrdered($new));
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
