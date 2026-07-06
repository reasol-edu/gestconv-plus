<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\ActivityLog;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class ActivityLogControllerTest extends ControllerTestCase
{
    public function testIndexRendersCuratedActionLabelAndChangesDiff(): void
    {
        $admin = (new Teacher(new PersonName('Admin', 'User')))->setUsername('admin.' . uniqid('', false))->setAdmin(true);
        $this->persist($admin);

        $log = new ActivityLog(
            new \DateTimeImmutable(),
            '127.0.0.1',
            'sanction_measure.updated',
            $admin,
            null,
            null,
            ['entityId' => 'abc', 'changes' => ['name' => ['before' => 'Antes', 'after' => 'Después']]],
        );
        $this->persist($log);

        $this->loginAs($admin);

        $crawler = $this->client->request('GET', '/admin/registro-actividad');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Modificación de medida disciplinaria', $crawler->text());
        self::assertStringContainsString('Antes', $crawler->text());
        self::assertStringContainsString('Después', $crawler->text());
    }

    public function testIndexRendersRawJsonForUncuratedActionType(): void
    {
        $admin = (new Teacher(new PersonName('Admin', 'User')))->setUsername('admin.' . uniqid('', false))->setAdmin(true);
        $this->persist($admin);

        $log = new ActivityLog(
            new \DateTimeImmutable(),
            '127.0.0.1',
            'session.login',
            $admin,
        );
        $this->persist($log);

        $this->loginAs($admin);

        $this->client->request('GET', '/admin/registro-actividad');

        self::assertResponseIsSuccessful();
    }
}
