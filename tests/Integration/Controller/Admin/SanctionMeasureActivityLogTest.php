<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class SanctionMeasureActivityLogTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        putenv('APP_LOG=true');
        $_ENV['APP_LOG']    = 'true';
        $_SERVER['APP_LOG'] = 'true';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('APP_LOG=false');
        $_ENV['APP_LOG']    = 'false';
        $_SERVER['APP_LOG'] = 'false';
    }

    public function testCreatingMeasureLogsSanctionMeasureCreated(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/sanciones/medidas');
        $token    = $crawler->filter('form[action$="medidas/nueva"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/sanciones/medidas/nueva', [
            '_token'      => $token,
            'name'        => 'Medida de prueba',
            'category_id' => $category->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction_measure.created', $logs[0]->getActionType());
        self::assertSame('Medida de prueba', $logs[0]->getData()['name'] ?? null);
    }

    public function testEditingMeasureLogsSanctionMeasureUpdatedWithDiff(): void
    {
        [$cadmin, $centre, $category, $measure] = $this->makeScenarioWithMeasure();
        $this->loginAs($cadmin);

        $centreId  = $centre->getId()->toRfc4122();
        $measureId = $measure->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/centros/' . $centreId . '/sanciones/medidas/' . $measureId . '/editar');
        $token     = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/sanciones/medidas/' . $measureId . '/editar', [
            '_token'         => $token,
            'name'           => 'Medida actualizada',
            'category_id'    => $category->getId()->toRfc4122(),
            'has_date_range' => '1',
            'active'         => '1',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction_measure.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('Medida inicial', $changes['name']['before'] ?? null);
        self::assertSame('Medida actualizada', $changes['name']['after'] ?? null);
        self::assertSame(false, $changes['hasDateRange']['before'] ?? null);
        self::assertSame(true, $changes['hasDateRange']['after'] ?? null);
    }

    public function testDeletingMeasureLogsSanctionMeasureDeleted(): void
    {
        [$cadmin, $centre, , $measure] = $this->makeScenarioWithMeasure();
        $this->loginAs($cadmin);

        $centreId  = $centre->getId()->toRfc4122();
        $measureId = $measure->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/centros/' . $centreId . '/sanciones/medidas');
        $token     = $crawler->filter('form[action$="' . $measureId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/sanciones/medidas/' . $measureId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction_measure.deleted', $logs[0]->getActionType());
        self::assertSame($measureId, $logs[0]->getData()['entityId'] ?? null);
    }

    public function testCreatingCategoryLogsSanctionMeasureCategoryCreated(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/sanciones/categorias/nueva');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/sanciones/categorias/nueva', [
            '_token' => $token,
            'name'   => 'Categoria de prueba',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction_measure_category.created', $logs[0]->getActionType());
        self::assertSame('Categoria de prueba', $logs[0]->getData()['name'] ?? null);
    }

    public function testEditingCategoryLogsSanctionMeasureCategoryUpdatedWithDiff(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $categoryId = $category->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centros/' . $centreId . '/sanciones/categorias/' . $categoryId . '/editar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/sanciones/categorias/' . $categoryId . '/editar', [
            '_token' => $token,
            'name'   => 'Categoria actualizada',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction_measure_category.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('Sanciones', $changes['name']['before'] ?? null);
        self::assertSame('Categoria actualizada', $changes['name']['after'] ?? null);
    }

    public function testDeletingCategoryLogsSanctionMeasureCategoryDeleted(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $categoryId = $category->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centros/' . $centreId . '/sanciones/medidas');
        $token      = $crawler->filter('form[action$="categorias/' . $categoryId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/sanciones/categorias/' . $categoryId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction_measure_category.deleted', $logs[0]->getActionType());
        self::assertSame($categoryId, $logs[0]->getData()['entityId'] ?? null);
    }

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('43' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: SanctionMeasureCategory} */
    private function makeScenarioWithCategory(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $category          = (new SanctionMeasureCategory())->setEducationalCentre($centre)->setName('Sanciones')->setPosition(0);
        $this->persist($category);

        return [$cadmin, $centre, $category];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: SanctionMeasureCategory, 3: SanctionMeasure} */
    private function makeScenarioWithMeasure(): array
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $measure                      = (new SanctionMeasure())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Medida inicial')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($measure);

        return [$cadmin, $centre, $category, $measure];
    }
}
