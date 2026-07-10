<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class LocationOptionActivityLogTest extends ControllerTestCase
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

    public function testCreatingOptionLogsLocationOptionCreated(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones');
        $token    = $crawler->filter('form[action$="ubicaciones/nueva"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/nueva', [
            '_token'      => $token,
            'name'        => 'Ubicación de prueba',
            'category_id' => $category->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('location_option.created', $logs[0]->getActionType());
        self::assertSame('Ubicación de prueba', $logs[0]->getData()['name'] ?? null);
    }

    public function testEditingOptionLogsLocationOptionUpdatedWithDiff(): void
    {
        [$cadmin, $centre, $category, $option] = $this->makeScenarioWithOption();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $optionId = $option->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones/' . $optionId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/' . $optionId . '/editar', [
            '_token'      => $token,
            'name'        => 'Nombre actualizado',
            'category_id' => $category->getId()->toRfc4122(),
            'active'      => '1',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('location_option.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('Ubicación inicial', $changes['name']['before'] ?? null);
        self::assertSame('Nombre actualizado', $changes['name']['after'] ?? null);
    }

    public function testDeletingOptionLogsLocationOptionDeleted(): void
    {
        [$cadmin, $centre, , $option] = $this->makeScenarioWithOption();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $optionId = $option->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones');
        $token    = $crawler->filter('form[action$="' . $optionId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/' . $optionId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('location_option.deleted', $logs[0]->getActionType());
        self::assertSame($optionId, $logs[0]->getData()['entityId'] ?? null);
    }

    public function testCreatingCategoryLogsLocationOptionCategoryCreated(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones/categorias/nueva');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/categorias/nueva', [
            '_token' => $token,
            'name'   => 'Categoria de prueba',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('location_option_category.created', $logs[0]->getActionType());
        self::assertSame('Categoria de prueba', $logs[0]->getData()['name'] ?? null);
    }

    public function testEditingCategoryLogsLocationOptionCategoryUpdatedWithDiff(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $categoryId = $category->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones/categorias/' . $categoryId . '/editar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/categorias/' . $categoryId . '/editar', [
            '_token' => $token,
            'name'   => 'Categoria actualizada',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('location_option_category.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('General', $changes['name']['before'] ?? null);
        self::assertSame('Categoria actualizada', $changes['name']['after'] ?? null);
    }

    public function testDeletingCategoryLogsLocationOptionCategoryDeleted(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $categoryId = $category->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centro/' . $centreId . '/ubicaciones');
        $token      = $crawler->filter('form[action$="categorias/' . $categoryId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/ubicaciones/categorias/' . $categoryId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('location_option_category.deleted', $logs[0]->getActionType());
        self::assertSame($categoryId, $logs[0]->getData()['entityId'] ?? null);
    }

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.local.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('45' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: LocationOptionCategory} */
    private function makeScenarioWithCategory(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $category          = $this->makeCategory($centre, 'General', 0);
        $this->persist($category);

        return [$cadmin, $centre, $category];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: LocationOptionCategory, 3: LocationOption} */
    private function makeScenarioWithOption(): array
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $option                       = $this->makeOption($centre, $category, 'Ubicación inicial', 0);
        $this->persist($option);

        return [$cadmin, $centre, $category, $option];
    }

    private function makeCategory(EducationalCentre $centre, string $name, int $position): LocationOptionCategory
    {
        return (new LocationOptionCategory())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setPosition($position);
    }

    private function makeOption(
        EducationalCentre $centre,
        LocationOptionCategory $category,
        string $name,
        int $position,
    ): LocationOption {
        return (new LocationOption())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition($position)
            ->setActive(true);
    }
}
