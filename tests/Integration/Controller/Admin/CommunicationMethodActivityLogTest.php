<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\ActivityLog;
use App\Entity\CommunicationMethod;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class CommunicationMethodActivityLogTest extends ControllerTestCase
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

    public function testCreatingMethodLogsCommunicationMethodCreated(): void
    {
        [$cadmin, $centre] = $this->makeScenario();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/metodos-comunicacion');
        $token    = $crawler->filter('form[action$="metodos-comunicacion/nuevo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/metodos-comunicacion/nuevo', [
            '_token' => $token,
            'name'   => 'Mensajería Pasen',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('communication_method.created', $logs[0]->getActionType());
        self::assertSame('Mensajería Pasen', $logs[0]->getData()['name'] ?? null);
    }

    public function testEditingMethodLogsCommunicationMethodUpdatedWithDiff(): void
    {
        [$cadmin, $centre, $method] = $this->makeScenarioWithMethod();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $methodId = $method->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/metodos-comunicacion/' . $methodId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/metodos-comunicacion/' . $methodId . '/editar', [
            '_token' => $token,
            'name'   => 'Nombre actualizado',
            'active' => '0',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('communication_method.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('Llamada telefónica', $changes['name']['before'] ?? null);
        self::assertSame('Nombre actualizado', $changes['name']['after'] ?? null);
        self::assertSame(true, $changes['active']['before'] ?? null);
        self::assertSame(false, $changes['active']['after'] ?? null);
    }

    public function testDeletingMethodLogsCommunicationMethodDeleted(): void
    {
        [$cadmin, $centre, $method] = $this->makeScenarioWithMethod();
        $this->loginAs($cadmin);

        $centreId = $centre->getId()->toRfc4122();
        $methodId = $method->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centros/' . $centreId . '/metodos-comunicacion');
        $token    = $crawler->filter('form[action$="' . $methodId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centros/' . $centreId . '/metodos-comunicacion/' . $methodId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('communication_method.deleted', $logs[0]->getActionType());
        self::assertSame($methodId, $logs[0]->getData()['entityId'] ?? null);
    }

    /** @return array{0: Teacher, 1: EducationalCentre} */
    private function makeScenario(): array
    {
        $cadmin = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('44' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);

        $this->persist($cadmin, $centre, $year);

        return [$cadmin, $centre];
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: CommunicationMethod} */
    private function makeScenarioWithMethod(): array
    {
        [$cadmin, $centre] = $this->makeScenario();
        $method             = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($method);

        return [$cadmin, $centre, $method];
    }
}
