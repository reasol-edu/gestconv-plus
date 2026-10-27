<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\SchoolEventType;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class SchoolEventControllerTest extends ControllerTestCase
{
    /** Id del tipo de actividad que crea makeScenario(). */
    private string $typeId = '';

    // ── new ───────────────────────────────────────────────────────────────────

    public function testNewGetRendersFormToAdmin(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/actividades/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testNewGetDeniesNonAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.events.noadmin');
        $this->persist($teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/actividades/nuevo');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewPrefillsDateFromQueryParam(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/actividades/nuevo?date=2026-09-15');

        self::assertResponseIsSuccessful();
        self::assertSame('2026-09-15', $this->client->getCrawler()->filter('#date')->attr('value'));
    }

    public function testNewPostCreatesGeneralEventAndRedirects(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'      => $token,
            'date'        => '2026-09-15',
            'start_time'  => '09:00',
            'end_time'    => '10:00',
            'name'        => 'Jornada de puertas abiertas',
            'description' => 'Presentación a nuevas familias',
            'url'         => 'https://example.org/jornada',
            'scope'       => 'general',
            'type'        => $this->typeId,
            'location'    => 'Salón de actos',
            'organizing_department' => 'Orientación',
        ]);

        self::assertResponseRedirects();
        self::assertStringEndsWith('/actividades', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        $events = $this->em->getRepository(SchoolEvent::class)->findAll();
        self::assertCount(1, $events);
        self::assertSame('Jornada de puertas abiertas', $events[0]->getName());
        self::assertTrue($events[0]->isGeneral());
        self::assertSame('https://example.org/jornada', $events[0]->getUrl());
        self::assertCount(0, $events[0]->getGroups());
        self::assertSame($this->typeId, $events[0]->getType()?->getId()->toRfc4122());
        self::assertSame('Salón de actos', $events[0]->getLocation());
        self::assertSame('Orientación', $events[0]->getOrganizingDepartment());
    }

    public function testNewPostCreatesRestrictedEventWithGroups(): void
    {
        [$admin, $centre, $group] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Reunión de tutoría',
            'scope'      => 'restricted',
            'type'       => $this->typeId,
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

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => 'token-invalido',
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'scope'      => 'general',
            'type'       => $this->typeId,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewPostWithEmptyNameRendersFormAgain(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => '',
            'scope'      => 'general',
            'type'       => $this->typeId,
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    public function testNewPostWithEndTimeBeforeStartTimeRendersFormAgain(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '11:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'scope'      => 'general',
            'type'       => $this->typeId,
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    public function testNewPostWithoutGroupsIsGeneralWhateverTheScopeSent(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'type'       => $this->typeId,
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $events = $this->em->getRepository(SchoolEvent::class)->findAll();
        self::assertCount(1, $events);
        self::assertTrue($events[0]->isGeneral(), 'sin grupos, el evento es general sin elegir nada');
    }

    public function testNewPostWithoutTypeRendersFormAgain(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Sin tipo',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Elige un tipo de actividad');
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    public function testNewPostWithATypeOfAnotherCentreIsRejected(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $other            = (new EducationalCentre())->setCode('99999999')->setName('Otro centro')->setCity('Jaén');
        $foreignType      = (new SchoolEventType())->setEducationalCentre($other)->setName('Ajena');
        $this->persist($other, $foreignType);
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Tipo ajeno',
            'type'       => $foreignType->getId()->toRfc4122(),
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    public function testNewPostStoresCoordinatorAndCompanionsOfTheYear(): void
    {
        [$admin, $centre, , $year] = $this->makeScenario();
        $coordinator = $this->makeTeacher('coordina.' . uniqid('', false));
        $companion   = $this->makeTeacher('acompana.' . uniqid('', false));
        $outsider    = $this->makeTeacher('ajeno.' . uniqid('', false));
        $coordinator->addAcademicYear($year);
        $companion->addAcademicYear($year);
        $this->persist($coordinator, $companion, $outsider);
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'      => $token,
            'date'        => '2026-09-15',
            'start_time'  => '09:00',
            'end_time'    => '10:00',
            'name'        => 'Visita',
            'type'        => $this->typeId,
            'coordinator' => $coordinator->getId()->toRfc4122(),
            // El coordinador y quien no es del curso no entran como acompañantes.
            'companions'  => [$companion->getId()->toRfc4122(), $coordinator->getId()->toRfc4122(), $outsider->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $event = $this->em->getRepository(SchoolEvent::class)->findAll()[0];
        self::assertSame($coordinator->getId()->toRfc4122(), $event->getCoordinator()?->getId()->toRfc4122());
        self::assertCount(1, $event->getCompanions());
        self::assertSame($companion->getId()->toRfc4122(), $event->getCompanions()->first()->getId()->toRfc4122());
    }

    public function testEditingKeepsACoordinatorAndCompanionWhoLeftTheYear(): void
    {
        [$admin, $centre, , $year] = $this->makeScenario();
        $coordinator = $this->makeTeacher('sale.coord.' . uniqid('', false));
        $companion   = $this->makeTeacher('sale.acomp.' . uniqid('', false));
        $this->persist($coordinator, $companion);
        // Ninguno de los dos pertenece ya al curso, pero siguen en la actividad.
        $event = $this->makeEvent($year, 'Visita');
        $event->setCoordinator($coordinator)->addCompanion($companion);
        $this->persist($event);
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/editar');
        self::assertCount(1, $crawler->filter('select[name="coordinator"] option[value="' . $coordinator->getId()->toRfc4122() . '"][selected]'));
        self::assertCount(1, $crawler->filter('select[name="companions[]"] option[value="' . $companion->getId()->toRfc4122() . '"][selected]'));
        $token = $crawler->filter('form:not([action]) [name="_token"]')->attr('value');

        $this->client->request('POST', '/actividades/' . $event->getId()->toRfc4122() . '/editar', [
            '_token'      => $token,
            'date'        => '2026-09-15',
            'start_time'  => '09:00',
            'end_time'    => '10:00',
            'name'        => 'Visita renombrada',
            'type'        => $this->typeId,
            'coordinator' => $coordinator->getId()->toRfc4122(),
            'companions'  => [$companion->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $saved = $this->em->getRepository(SchoolEvent::class)->findAll()[0];
        self::assertSame($coordinator->getId()->toRfc4122(), $saved->getCoordinator()?->getId()->toRfc4122());
        self::assertCount(1, $saved->getCompanions());
    }

    public function testTheOldEventsAddressRedirectsToActivities(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/eventos/nuevo?date=2026-09-15');

        self::assertResponseRedirects('/actividades/nuevo?date=2026-09-15', 301);
    }

    public function testEverythingIsClosedWhenTheActivitiesAreDisabled(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $definition = (new SettingDefinition())->setKey('features.events')->setType(SettingType::Boolean)
            ->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new GlobalSettingValue())->setDefinition($definition)->setValue('false'));
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/actividades/nuevo');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/tipos-actividad');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/actividades');
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/calendario');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('nav a[href="/actividades"]'), 'sin entrada de actividades en el menú');
    }

    public function testNewPostGeneralEventKeepsItsAssociatedGroups(): void
    {
        [$admin, $centre, $group] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Excursión de 1ºA',
            'scope'      => 'general',
            'type'       => $this->typeId,
            'groups'     => [$group->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $events = $this->em->getRepository(SchoolEvent::class)->findAll();
        self::assertTrue($events[0]->isGeneral());
        self::assertCount(1, $events[0]->getGroups(), 'un evento general también puede estar asociado a grupos');
    }

    public function testNewPostWithGroupsButNoScopeRendersFormAgain(): void
    {
        [$admin, $centre, $group] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'groups'     => [$group->getId()->toRfc4122()],
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    // ── quién puede publicar ──────────────────────────────────────────────────

    private function definePublishScope(string $global): void
    {
        $definition = (new SettingDefinition())->setKey('events.publish_scope')->setType(SettingType::Choice)
            ->setDefaultValue('management')->setChoices('all,management')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new GlobalSettingValue())->setDefinition($definition)->setValue($global));
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: AcademicYear} */
    private function makeMemberScenario(): array
    {
        [$admin, $centre, $group, $year] = $this->makeScenario();
        $teacher = $this->makeTeacher('teacher.member.' . uniqid('', false));
        $year->addTeacher($teacher);
        $this->persist($teacher);

        return [$teacher, $centre, $group, $year];
    }

    public function testATeacherCannotPublishByDefault(): void
    {
        $this->definePublishScope('management');
        [$teacher, $centre] = $this->makeMemberScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/actividades/nuevo');

        self::assertResponseStatusCodeSame(403);
    }

    public function testATeacherOfTheCentreCanPublishWhenTheSettingIsAll(): void
    {
        $this->definePublishScope('all');
        [$teacher, $centre] = $this->makeMemberScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/actividades/nuevo');

        self::assertResponseIsSuccessful();
    }

    public function testATeacherOutsideTheCentreCannotPublishEvenWhenTheSettingIsAll(): void
    {
        $this->definePublishScope('all');
        [, $centre] = $this->makeScenario();
        $stranger = $this->makeTeacher('teacher.stranger');
        $this->persist($stranger);
        $this->loginAs($stranger, $centre);

        $this->client->request('GET', '/actividades/nuevo');

        self::assertResponseStatusCodeSame(403);
    }

    public function testATeacherPublishingCannotTouchAnActivityOfSomeoneElse(): void
    {
        $this->definePublishScope('all');
        [$teacher, $centre, , $year] = $this->makeMemberScenario();
        $other = $this->makeTeacher('teacher.other.' . uniqid('', false));
        $this->persist($other);
        $event = $this->makeEvent($year, 'Reunión ajena')->setCreatedBy($other);
        $this->persist($event);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/editar');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/actividades/' . $event->getId()->toRfc4122() . '/eliminar', ['_token' => 'da-igual']);
        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    public function testActivitiesWithoutAuthorAreOnlyForManagement(): void
    {
        $this->definePublishScope('all');
        [$teacher, $centre, , $year] = $this->makeMemberScenario();
        $event = $this->makeEvent($year, 'Anterior al dato de autoría');
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnActivityCoordinatorManagesEveryActivityLikeAnAdmin(): void
    {
        // Aunque el ajuste solo permita publicar a la dirección.
        $this->definePublishScope('management');
        [$teacher, $centre, , $year] = $this->makeMemberScenario();
        $centre->addActivityCoordinator($teacher);
        $other = $this->makeTeacher('teacher.author.' . uniqid('', false));
        $this->persist($other);
        $event = $this->makeEvent($year, 'De otra persona')->setCreatedBy($other);
        $this->persist($event);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/actividades/nuevo');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/editar');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->attr('value');

        $this->client->request('POST', '/actividades/' . $event->getId()->toRfc4122() . '/eliminar', ['_token' => $token]);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    public function testTheAuthorIsRecordedWhenPublishing(): void
    {
        $this->definePublishScope('all');
        [$teacher, $centre] = $this->makeMemberScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Mía',
            'type'       => $this->typeId,
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $event = $this->em->getRepository(SchoolEvent::class)->findAll()[0];
        self::assertSame($teacher->getId()->toRfc4122(), $event->getCreatedBy()?->getId()->toRfc4122());
    }

    public function testATeacherPublishingCanEditTheirOwnActivity(): void
    {
        $this->definePublishScope('all');
        [$teacher, $centre, , $year] = $this->makeMemberScenario();
        $event = $this->makeEvent($year, 'Claustro')->setCreatedBy($teacher);
        $this->persist($event);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/editar');

        self::assertResponseIsSuccessful();
    }

    public function testTheMenuOffersActivitiesToWhoCanPublish(): void
    {
        $this->definePublishScope('all');
        [$teacher, $centre] = $this->makeMemberScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/calendario');

        self::assertGreaterThan(0, $crawler->filter('nav a[href="/actividades"]')->count());

        $this->client->request('GET', '/actividades');
        self::assertResponseIsSuccessful();
    }

    public function testNewPostWithInvalidUrlRendersFormAgain(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Evento',
            'scope'      => 'general',
            'type'       => $this->typeId,
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

        $crawler = $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/editar');
        $token   = $crawler->filter('form:not([action]) [name="_token"]')->attr('value');

        $this->client->request('POST', '/actividades/' . $event->getId()->toRfc4122() . '/editar', [
            '_token'     => $token,
            'date'       => '2026-09-20',
            'start_time' => '11:00',
            'end_time'   => '12:00',
            'name'       => 'Nombre actualizado',
            'scope'      => 'restricted',
            'type'       => $this->typeId,
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

        $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(403);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeletePostRemovesEventAndRedirects(): void
    {
        [$admin, $centre, , $year] = $this->makeScenario();
        $event = $this->makeEvent($year, 'Evento a eliminar');
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/editar');
        $token   = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->attr('value');

        $this->client->request('POST', '/actividades/' . $event->getId()->toRfc4122() . '/eliminar', [
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

        $this->client->request('POST', '/actividades/' . $event->getId()->toRfc4122() . '/eliminar', [
            '_token' => 'token-invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(SchoolEvent::class)->findAll());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    public function testTheStudentsScreenListsTheStudentsOfTheActivitysGroupsOnly(): void
    {
        [$admin, $centre, $group, $year] = $this->makeScenario();
        $otherGroup = (new Group())->setName('1ºB')->setCourse($group->getCourse());
        $inGroup    = $this->makeStudent('Ana', $group);
        $outside    = $this->makeStudent('Beatriz', $otherGroup);
        $this->persist($otherGroup, $inGroup, $outside);
        $event = $this->makeEvent($year, 'Visita')->addGroup($group)->addStudent($inGroup);
        $this->persist($event);
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/alumnado');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="students[]"]'));
        self::assertCount(1, $crawler->filter('input[name="students[]"][checked]'));
        self::assertSelectorTextContains('body', 'Ana Test');
    }

    public function testSavingTheStudentsScreenStoresOnlyStudentsOfTheGroups(): void
    {
        [$admin, $centre, $group, $year] = $this->makeScenario();
        $otherGroup = (new Group())->setName('1ºB')->setCourse($group->getCourse());
        $inGroup    = $this->makeStudent('Ana', $group);
        $inOther    = $this->makeStudent('Beatriz', $otherGroup);
        $this->persist($otherGroup, $inGroup, $inOther);
        $event = $this->makeEvent($year, 'Visita')->addGroup($group);
        $this->persist($event);
        $this->loginAs($admin, $centre);
        $eventId = $event->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/actividades/' . $eventId . '/alumnado');
        $token   = $crawler->filter('[name="_token"]')->attr('value');
        $this->client->request('POST', '/actividades/' . $eventId . '/alumnado', [
            '_token'   => $token,
            'students' => [$inGroup->getId()->toRfc4122(), $inOther->getId()->toRfc4122(), 'no-es-un-id'],
        ]);

        self::assertResponseRedirects('/actividades/' . $eventId);
        $this->em->clear();
        $saved = $this->em->getRepository(SchoolEvent::class)->find($event->getId());
        self::assertCount(1, $saved->getStudents());
        self::assertSame('Ana', $saved->getStudents()->first()->getName()->getFirstName());
    }

    public function testSavingTheStudentsScreenWithNoneCheckedEmptiesTheSelection(): void
    {
        [$admin, $centre, $group, $year] = $this->makeScenario();
        $ana = $this->makeStudent('Ana', $group);
        $this->persist($ana);
        $event = $this->makeEvent($year, 'Visita')->addGroup($group)->addStudent($ana);
        $this->persist($event);
        $this->loginAs($admin, $centre);
        $eventId = $event->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/actividades/' . $eventId . '/alumnado');
        $this->client->request('POST', '/actividades/' . $eventId . '/alumnado', ['_token' => $crawler->filter('[name="_token"]')->attr('value')]);

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SchoolEvent::class)->find($event->getId())->getStudents());
    }

    public function testTheStudentsScreenRejectsAnInvalidCsrfToken(): void
    {
        [$admin, $centre, $group, $year] = $this->makeScenario();
        $event = $this->makeEvent($year, 'Visita')->addGroup($group);
        $this->persist($event);
        $this->loginAs($admin, $centre);

        $this->client->request('POST', '/actividades/' . $event->getId()->toRfc4122() . '/alumnado', ['_token' => 'mal']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditingKeepsTheStudentsAndDropsTheOnesOfARemovedGroup(): void
    {
        [$admin, $centre, $group, $year] = $this->makeScenario();
        $otherGroup = (new Group())->setName('1ºB')->setCourse($group->getCourse());
        $ana        = $this->makeStudent('Ana', $group);
        $beatriz    = $this->makeStudent('Beatriz', $otherGroup);
        $this->persist($otherGroup, $ana, $beatriz);
        $event = $this->makeEvent($year, 'Excursión')->addGroup($group)->addGroup($otherGroup)->addStudent($ana)->addStudent($beatriz);
        $this->persist($event);
        $this->loginAs($admin, $centre);
        $eventId = $event->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/actividades/' . $eventId . '/editar');
        $token   = $crawler->filter('form.space-y-6 [name="_token"]')->attr('value');

        $this->client->request('POST', '/actividades/' . $eventId . '/editar', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Excursión',
            'scope'      => 'restricted',
            'type'       => $this->typeId,
            'groups'     => [$group->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $saved = $this->em->getRepository(SchoolEvent::class)->find($event->getId());
        self::assertCount(1, $saved->getStudents());
        self::assertSame('Ana', $saved->getStudents()->first()->getName()->getFirstName());
    }

    public function testNewPostCanSaveTheActivityAsADraft(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        self::assertSelectorExists('input[name="draft"][value="1"]');
        $token = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Por confirmar',
            'type'       => $this->typeId,
            'draft'      => '1',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertTrue($this->em->getRepository(SchoolEvent::class)->findAll()[0]->isDraft());
    }

    public function testActivitiesArePublishedByDefault(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/actividades/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/actividades/nuevo', [
            '_token'     => $token,
            'date'       => '2026-09-15',
            'start_time' => '09:00',
            'end_time'   => '10:00',
            'name'       => 'Normal',
            'type'       => $this->typeId,
        ]);

        $this->em->clear();
        self::assertFalse($this->em->getRepository(SchoolEvent::class)->findAll()[0]->isDraft());
    }

    public function testTheDetailsPageListsTheStudentsByGroupAndOffersTheReport(): void
    {
        [$admin, $centre, $group, $year] = $this->makeScenario();
        $ana = $this->makeStudent('Ana', $group);
        $this->persist($ana);
        $event = $this->makeEvent($year, 'Excursión')->addGroup($group)->addStudent($ana)->setDraft(true);
        $this->persist($event);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Excursión');
        self::assertSelectorTextContains('body', 'borrador');
        self::assertSelectorTextContains('body', 'Test, Ana');
        self::assertSelectorExists('form[action$="/informe"] input[name="groups[]"][checked]');
    }

    public function testTheReportIsAPdfWithOrWithoutTheStudentLists(): void
    {
        [$admin, $centre, $group, $year] = $this->makeScenario();
        $ana = $this->makeStudent('Ana', $group);
        $this->persist($ana);
        $event = $this->makeEvent($year, 'Excursión')->addGroup($group)->addStudent($ana);
        $this->persist($event);
        $this->loginAs($admin, $centre);
        $url = '/actividades/' . $event->getId()->toRfc4122() . '/informe';

        foreach ([$url, $url . '?options=1&groups[]=' . $group->getId()->toRfc4122(), $url . '?options=1'] as $address) {
            $this->client->request('GET', $address);
            self::assertResponseIsSuccessful();
            self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
            self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testTheReportOfAnActivityWithoutStudentsOrGroupsStillWorks(): void
    {
        [$admin, $centre, , $year] = $this->makeScenario();
        $event = $this->makeEvent($year, 'Claustro');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/informe');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testATeacherCannotOpenTheDetailsOrTheReportOfSomeoneElsesActivity(): void
    {
        $this->definePublishScope('all');
        [$teacher, $centre, , $year] = $this->makeMemberScenario();
        $event = $this->makeEvent($year, 'Ajena');
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/informe');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAuthorCanGenerateTheReportOfTheirOwnActivity(): void
    {
        $this->definePublishScope('all');
        [$teacher, $centre, , $year] = $this->makeMemberScenario();
        $event = $this->makeEvent($year, 'Mía')->setCreatedBy($teacher);
        $this->persist($event);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/actividades/' . $event->getId()->toRfc4122() . '/informe');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    private function makeStudent(string $firstName, Group $group): Student
    {
        $student = (new Student(new PersonName($firstName, 'Test')))->setStudentId('NIE' . uniqid('', false));
        $student->addGroup($group);

        return $student;
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: AcademicYear} */
    private function makeScenario(): array
    {
        $suffix = uniqid('', false);
        $admin  = (new Teacher(new PersonName('Test', 'Admin')))->setUsername('admin.events.' . $suffix)->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('5' . substr($suffix, 0, 7))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course = (new Course())->setName('DAW')->setAcademicYear($year);
        $group  = (new Group())->setName('1ºA')->setCourse($course);

        $type   = (new SchoolEventType())->setEducationalCentre($centre)->setName('Complementaria')->setColor('#dc2626');

        $centre->setActiveAcademicYear($year);
        $this->persist($admin, $centre, $year, $course, $group, $type);
        $this->typeId = $type->getId()->toRfc4122();

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
