<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class IncidentBehaviorActivityLogTest extends ControllerTestCase
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

    public function testCreatingBehaviorLogsIncidentBehaviorCreated(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/conductas');
        $token    = $crawler->filter('form[action$="conductas/nueva"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/nueva', [
            '_token'      => $token,
            'name'        => 'Conducta de prueba',
            'category_id' => $category->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_behavior.created', $logs[0]->getActionType());
        self::assertSame('Conducta de prueba', $logs[0]->getData()['name'] ?? null);
    }

    public function testEditingBehaviorLogsIncidentBehaviorUpdatedWithDiff(): void
    {
        [$cadmin, $centre, $category, $behavior] = $this->makeScenarioWithBehavior();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $behaviorId = $behavior->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centro/' . $centreId . '/conductas/' . $behaviorId . '/editar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/' . $behaviorId . '/editar', [
            '_token'      => $token,
            'name'        => 'Nombre actualizado',
            'category_id' => $category->getId()->toRfc4122(),
            'active'      => '1',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_behavior.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('Conducta inicial', $changes['name']['before'] ?? null);
        self::assertSame('Nombre actualizado', $changes['name']['after'] ?? null);
    }

    public function testDeletingBehaviorLogsIncidentBehaviorDeleted(): void
    {
        [$cadmin, $centre, , $behavior] = $this->makeScenarioWithBehavior();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $behaviorId = $behavior->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centro/' . $centreId . '/conductas');
        $token      = $crawler->filter('form[action$="' . $behaviorId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/' . $behaviorId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_behavior.deleted', $logs[0]->getActionType());
        self::assertSame($behaviorId, $logs[0]->getData()['entityId'] ?? null);
    }

    public function testCreatingCategoryLogsIncidentBehaviorCategoryCreated(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/conductas/categorias/nueva');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/categorias/nueva', [
            '_token'  => $token,
            'name'    => 'Categoria de prueba',
            'serious' => '',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_behavior_category.created', $logs[0]->getActionType());
        self::assertSame('Categoria de prueba', $logs[0]->getData()['name'] ?? null);
    }

    public function testEditingCategoryLogsIncidentBehaviorCategoryUpdatedWithDiff(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId  = $centre->getId()->toRfc4122();
        $categoryId = $category->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/centro/' . $centreId . '/conductas/categorias/' . $categoryId . '/editar');
        $token     = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/categorias/' . $categoryId . '/editar', [
            '_token'  => $token,
            'name'    => 'Categoria actualizada',
            'serious' => '1',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_behavior_category.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('Contrarias', $changes['name']['before'] ?? null);
        self::assertSame('Categoria actualizada', $changes['name']['after'] ?? null);
        self::assertSame(false, $changes['serious']['before'] ?? null);
        self::assertSame(true, $changes['serious']['after'] ?? null);
    }

    public function testDeletingCategoryLogsIncidentBehaviorCategoryDeleted(): void
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $this->loginAs($cadmin);

        $centreId   = $centre->getId()->toRfc4122();
        $categoryId = $category->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/centro/' . $centreId . '/conductas');
        $token      = $crawler->filter('form[action$="categorias/' . $categoryId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/conductas/categorias/' . $categoryId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_behavior_category.deleted', $logs[0]->getActionType());
        self::assertSame($categoryId, $logs[0]->getData()['entityId'] ?? null);
    }

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('42' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: IncidentBehaviorCategory} */
    private function makeScenarioWithCategory(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $category          = $this->makeCategory($centre, 'Contrarias', false, 0);
        $this->persist($category);

        return [$cadmin, $centre, $category];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: IncidentBehaviorCategory, 3: IncidentBehavior} */
    private function makeScenarioWithBehavior(): array
    {
        [$cadmin, $centre, $category] = $this->makeScenarioWithCategory();
        $behavior                     = $this->makeBehavior($centre, $category, 'Conducta inicial', 0);
        $this->persist($behavior);

        return [$cadmin, $centre, $category, $behavior];
    }

    private function makeCategory(EducationalCentre $centre, string $name, bool $serious, int $position): IncidentBehaviorCategory
    {
        return (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName($name)
            ->setSerious($serious)
            ->setPosition($position);
    }

    private function makeBehavior(
        EducationalCentre $centre,
        IncidentBehaviorCategory $category,
        string $name,
        int $position,
        bool $active = true,
    ): IncidentBehavior {
        return (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition($position)
            ->setActive($active);
    }
}
