<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

class SchoolEventListComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    public function testSearchFiltersByNameOrDescription(): void
    {
        [$centre, $year, , $admin] = $this->makeScenario('search');
        $this->makeEvent($year, 'Jornada de puertas abiertas', 'Presentación a nuevas familias');
        $this->makeEvent($year, 'Claustro extraordinario');
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('SchoolEventListComponent', ['centre' => $centre], $this->client);
        $render    = $component->set('search', 'puertas')->render();

        self::assertStringContainsString('Jornada de puertas abiertas', $render->crawler()->filter('tbody')->text());
        self::assertStringNotContainsString('Claustro extraordinario', $render->crawler()->filter('tbody')->text());

        $renderByDescription = $component->set('search', 'nuevas familias')->render();
        self::assertStringContainsString('Jornada de puertas abiertas', $renderByDescription->crawler()->filter('tbody')->text());
    }

    public function testGroupFilterShowsOnlyEventsForThatGroup(): void
    {
        [$centre, $year, $group, $admin] = $this->makeScenario('groupfilter');
        $otherGroup = (new Group())->setName('1ºB')->setCourse($group->getCourse());
        $this->persist($otherGroup);

        $this->makeEvent($year, 'Reunión 1ºA', groups: [$group]);
        $this->makeEvent($year, 'Reunión 1ºB', groups: [$otherGroup]);
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('SchoolEventListComponent', ['centre' => $centre], $this->client);
        $render    = $component->set('groupId', $group->getId()->toRfc4122())->render();

        $text = $render->crawler()->filter('tbody')->text();
        self::assertStringContainsString('Reunión 1ºA', $text);
        self::assertStringNotContainsString('Reunión 1ºB', $text);
    }

    public function testEmptyStateShownWhenNoEvents(): void
    {
        [$centre, , , $admin] = $this->makeScenario('empty');
        $this->loginAs($admin, $centre);

        $render = $this->createLiveComponent('SchoolEventListComponent', ['centre' => $centre], $this->client)->render();

        self::assertStringContainsString('No hay eventos registrados', $render->crawler()->text());
    }

    public function testListsGeneralAndRestrictedEventsWithScopeBadges(): void
    {
        [$centre, $year, $group, $admin] = $this->makeScenario('scope');
        $this->makeEvent($year, 'Evento general', general: true);
        $this->makeEvent($year, 'Evento de grupo', groups: [$group]);
        $this->loginAs($admin, $centre);

        $render = $this->createLiveComponent('SchoolEventListComponent', ['centre' => $centre], $this->client)->render();
        $text   = $render->crawler()->filter('tbody')->text();

        self::assertStringContainsString('Evento general', $text);
        self::assertStringContainsString('Evento de grupo', $text);
        self::assertStringContainsString('General', $text);
        self::assertStringContainsString('1ºA', $text);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: EducationalCentre, 1: AcademicYear, 2: Group, 3: Teacher} */
    private function makeScenario(string $suffix): array
    {
        $centre = (new EducationalCentre())->setCode('41' . substr(md5($suffix . 'selc'), 0, 6))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course = (new Course())->setName('DAW')->setAcademicYear($year);
        $group  = (new Group())->setName('1ºA')->setCourse($course);
        $admin  = $this->makeAdmin('admin.selc.' . $suffix);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $admin);

        return [$centre, $year, $group, $admin];
    }

    private function makeAdmin(string $username): Teacher
    {
        return (new Teacher(new \App\Entity\PersonName('Test', 'Admin')))->setUsername($username)->setAdmin(true);
    }

    /** @param list<Group> $groups */
    private function makeEvent(AcademicYear $year, string $name, string $description = '', array $groups = [], bool $general = false): SchoolEvent
    {
        $isGeneral = $general || $groups === [];
        $event     = (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2026-09-15'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName($name)
            ->setDescription($description !== '' ? $description : null)
            ->setGeneral($isGeneral);

        foreach ($groups as $group) {
            $event->addGroup($group);
        }

        $this->persist($event);

        return $event;
    }
}
