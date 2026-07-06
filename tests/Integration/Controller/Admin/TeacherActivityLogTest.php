<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\ActivityLog;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class TeacherActivityLogTest extends ControllerTestCase
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

    public function testCreatingTeacherLogsTeacherCreated(): void
    {
        $admin = $this->makeAdmin('admin.1');
        $this->persist($admin);
        $this->loginAs($admin);

        $crawler = $this->client->request('GET', '/admin/docentes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/docentes/nuevo', [
            '_token'      => $token,
            'first_name'  => 'Juan',
            'last_name'   => 'Garcia',
            'username'    => 'juan.garcia',
            'email'       => '',
            'password'    => 'secret123',
            'auth_method' => 'local',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('teacher.created', $logs[0]->getActionType());
        self::assertSame('juan.garcia', $logs[0]->getData()['username'] ?? null);
    }

    public function testEditingTeacherLogsTeacherUpdatedWithDiff(): void
    {
        $admin   = $this->makeAdmin('admin.1');
        $teacher = $this->makeTeacher('teacher.1');
        $this->persist($admin, $teacher);
        $this->loginAs($admin);

        $teacherId = $teacher->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/admin/docentes/' . $teacherId);
        $token     = $crawler->filter('form')->first()->filter('[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/docentes/' . $teacherId, [
            '_token'      => $token,
            'first_name'  => 'Modificado',
            'last_name'   => 'Apellido',
            'username'    => 'teacher.1',
            'email'       => '',
            'password'    => '',
            'auth_method' => 'local',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('teacher.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('Test', $changes['name']['before']['firstName'] ?? null);
        self::assertSame('Modificado', $changes['name']['after']['firstName'] ?? null);
        self::assertArrayNotHasKey('password', $changes);
    }

    public function testDeletingTeacherLogsTeacherDeleted(): void
    {
        $admin   = $this->makeAdmin('admin.1');
        $teacher = $this->makeTeacher('teacher.1');
        $this->persist($admin, $teacher);
        $this->loginAs($admin);

        $teacherId = $teacher->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/admin/docentes/' . $teacherId);
        $token     = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/admin/docentes/' . $teacherId . '/eliminar', ['_token' => $token]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('teacher.deleted', $logs[0]->getActionType());
        self::assertSame($teacherId, $logs[0]->getData()['entityId'] ?? null);
        self::assertSame('teacher.1', $logs[0]->getData()['username'] ?? null);
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function makeAdmin(string $username): Teacher
    {
        return (new Teacher(new PersonName('Admin', 'User')))->setUsername($username)->setAdmin(true);
    }
}
