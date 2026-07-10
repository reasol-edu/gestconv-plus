<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventSubscriber;

use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class ForcePasswordChangeSubscriberTest extends ControllerTestCase
{
    public function testFlaggedLocalTeacherIsRedirectedToForceChangeScreen(): void
    {
        $teacher = $this->makeTeacher('force.local', forcePasswordChange: true);
        $this->loginAs($teacher);

        $this->client->request('GET', '/perfil');

        self::assertResponseRedirects('/cambio-contrasena-obligatorio');
    }

    public function testFlaggedExternalTeacherIsNotRedirected(): void
    {
        $teacher = $this->makeTeacher('force.external', forcePasswordChange: true, external: true);
        $this->loginAs($teacher);

        $this->client->request('GET', '/perfil');

        self::assertResponseIsSuccessful();
    }

    public function testUnflaggedTeacherIsNotRedirected(): void
    {
        $teacher = $this->makeTeacher('force.none', forcePasswordChange: false);
        $this->loginAs($teacher);

        $this->client->request('GET', '/perfil');

        self::assertResponseIsSuccessful();
    }

    public function testForceChangeRouteItselfIsNeverRedirectedAway(): void
    {
        $teacher = $this->makeTeacher('force.self', forcePasswordChange: true);
        $this->loginAs($teacher);

        $this->client->request('GET', '/cambio-contrasena-obligatorio');

        self::assertResponseIsSuccessful();
    }

    public function testLogoutRouteIsNeverRedirectedAway(): void
    {
        $teacher = $this->makeTeacher('force.logout', forcePasswordChange: true);
        $this->loginAs($teacher);

        $this->client->request('GET', '/logout');

        self::assertResponseRedirects();
        self::assertNotSame('/cambio-contrasena-obligatorio', $this->client->getResponse()->headers->get('Location'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeTeacher(
        string $username,
        bool $forcePasswordChange = false,
        bool $external = false,
    ): Teacher {
        $teacher = (new Teacher(new PersonName('Test', 'User')))
            ->setUsername($username)
            ->setExternal($external)
            ->setForcePasswordChange($forcePasswordChange);
        $this->persist($teacher);

        return $teacher;
    }
}
