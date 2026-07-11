<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class EducationalCentreActivityLogTest extends ControllerTestCase
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

    public function testCreatingCentreLogsEducationalCentreAndAcademicYearCreated(): void
    {
        $admin = $this->makeAdmin('admin.' . uniqid('', false));
        $this->persist($admin);
        $this->loginAs($admin);

        $crawler = $this->client->request('GET', '/admin/centros/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/centros/nuevo', [
            '_token' => $token,
            'code'   => '45000001',
            'name'   => 'IES Test',
            'city'   => 'Sevilla',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findBy([], ['createdAt' => 'ASC']);
        self::assertCount(2, $logs);
        self::assertSame('educational_centre.created', $logs[0]->getActionType());
        self::assertSame('IES Test', $logs[0]->getData()['name'] ?? null);
        self::assertSame('academic_year.created', $logs[1]->getActionType());
    }

    public function testEditingCentreLogsEducationalCentreUpdatedWithDiff(): void
    {
        $admin  = $this->makeAdmin('admin.' . uniqid('', false));
        $centre = $this->makeCentre('45000002');
        $this->persist($admin, $centre);
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/admin/centros/' . $centreId);
        $token    = $crawler->filter('form')->first()->filter('[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/centros/' . $centreId, [
            '_token' => $token,
            'code'   => '45000002',
            'name'   => 'IES Modificado',
            'city'   => 'Malaga',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('educational_centre.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('IES 45000002', $changes['name']['before'] ?? null);
        self::assertSame('IES Modificado', $changes['name']['after'] ?? null);
        self::assertSame('Sevilla', $changes['city']['before'] ?? null);
        self::assertSame('Malaga', $changes['city']['after'] ?? null);
    }

    private function makeAdmin(string $username): Teacher
    {
        return (new Teacher(new PersonName('Admin', 'User')))->setUsername($username)->setAdmin(true);
    }

    private function makeCentre(string $code): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('IES ' . $code)->setCity('Sevilla');
    }
}
