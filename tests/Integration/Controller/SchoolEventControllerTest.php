<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class SchoolEventControllerTest extends ControllerTestCase
{
    // ── new ───────────────────────────────────────────────────────────────────

    public function testNewGetRendersFormToAdmin(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/eventos/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testNewGetDeniesNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.events.noadmin');
        $this->persist($teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/eventos/nuevo');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewPrefillsDateFromQueryParam(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/eventos/nuevo?date=2026-09-15');

        self::assertResponseIsSuccessful();
        self::assertSame('2026-09-15', $this->client->getCrawler()->filter('#date')->attr('value'));
    }

    public function testNewPostCreatesGeneralEventAndRedirects(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/eventos/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/eventos/nuevo', [
            '_token'      => $token,
            'date'        => '2026-09-15',
            'start_time'  => '09:00',
            'end_time'    => '10:00',
            'name'        => 'Jornada de puertas abiertas',
            'description' => 'Presentación a nuevas familias',
            'url'         => 'https://example.org/jornada',
            'scope'       => 'general',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('tab=events', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        $events = $this->em->getRepository(SchoolEvent::class)->findAll();
        self::assertCount(1, $events);
        self::assertSame('Jornada de puertas abiertas', $events[0]->getName());
        self::assertTrue($events[0]->isGeneral());
        self::assertSame('https://example.org/jornada', $events[0]->getUrl());
        self::assertCount(0, $events[0]->getGroups());
    }

    public function testNewPostCreatesRestrictedEventWithGroups(): void
    {
        [$admin, $centre, $group] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/eventos/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Reunión de tutoría',
            'scope'      => 'restricted',
            'groups'     => [$group->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $events = $this->em->getRepository(SchoolEvent::class)->findAll();
        self::assertCount(1, $events);
        self::assertFalse($events[0]->isGeneral());
        self::assertCount(1, $events[0]->getGroups());
        self::assertSame($group->getId()->toRfc4122(), $events[0]->getGroups()->first()->getId()->toRfc4122());
    }

    public function testNewPostWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => 'token-invalido',
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'scope'      => 'general',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewPostWithEmptyNameRendersFormAgain(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/eventos/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => '',
            'scope'      => 'general',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    public function testNewPostWithEndTimeBeforeStartTimeRendersFormAgain(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/eventos/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '11:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'scope'      => 'general',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    public function testNewPostRestrictedScopeWithoutGroupsRendersFormAgain(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/eventos/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'scope'      => 'restricted',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    public function testNewPostWithInvalidUrlRendersFormAgain(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/eventos/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/eventos/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'scope'      => 'general',
            'url'        => 'no-es-una-url',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditPostUpdatesEventAndRedirects(): void
    {
        [$admin, $centre, $group, $year] = $this->makeScenario();
        $event = $this->makeEvent($year, 'Nombre original');
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/eventos/' . $event->getId()->toRfc4122() . '/editar');
        $token   = $crawler->filter('form:not([action]) [name="_token"]')->attr('value');

        $this->client->request('POST', '/eventos/' . $event->getId()->toRfc4122() . '/editar', [
            '_token'     => $token,
            'date'       => '2026-09-20',
            'start_time' => '11:00',
            'end_time'   => '12:00',
            'name'       => 'Nombre actualizado',
            'scope'      => 'restricted',
            'groups'     => [$group->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->getRepository(SchoolEvent::class)->find($event->getId());
        self::assertSame('Nombre actualizado', $updated->getName());
        self::assertFalse($updated->isGeneral());
        self::assertCount(1, $updated->getGroups());
    }

    public function testEditGetDeniesNonAdmin(): void
    {
        [, $centre, , $year] = $this->makeScenario();
        $event   = $this->makeEvent($year, 'Evento');
        $teacher = $this->makeTeacher('teacher.events.edit.noadmin');
        $this->persist($teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/eventos/' . $event->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(403);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeletePostRemovesEventAndRedirects(): void
    {
        [$admin, $centre, , $year] = $this->makeScenario();
        $event = $this->makeEvent($year, 'Evento a eliminar');
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/eventos/' . $event->getId()->toRfc4122() . '/editar');
        $token   = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->attr('value');

        $this->client->request('POST', '/eventos/' . $event->getId()->toRfc4122() . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    public function testDeletePostWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, , $year] = $this->makeScenario();
        $event = $this->makeEvent($year, 'Evento protegido');
        $this->loginAs($admin, $centre);

        $this->client->request('POST', '/eventos/' . $event->getId()->toRfc4122() . '/eliminar', [
            '_token' => 'token-invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: AcademicYear} */
    private function makeScenario(): array
    {
        $suffix = uniqid('', false);
        $admin  = (new Teacher(new PersonName('Test', 'Admin')))->setUsername('admin.events.' . $suffix)->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('5' . substr($suffix, 0, 7))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course = (new Course())->setName('DAW')->setAcademicYear($year);
        $group  = (new Group())->setName('1ºA')->setCourse($course);

        $centre->setActiveAcademicYear($year);
        $this->persist($admin, $centre, $year, $course, $group);

        return [$admin, $centre, $group, $year];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function makeEvent(AcademicYear $year, string $name): SchoolEvent
    {
        $event = (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2026-09-15'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName($name)
            ->setGeneral(true);
        $this->persist($event);

        return $event;
    }
}
