<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\IssueManager;
use App\Service\IssuePrioritySeeder;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/** Listado de incidencias: visibilidad por rol, filtros, orden, cifras y cambio de estado en bloque. */
class IssueListComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private static int $seq = 0;

    public function testAReporterOnlySeesTheirOwnIssues(): void
    {
        $w = $this->makeWorld();
        $this->report($w, 'rita', 'Luz del aula 12');
        $this->report($w, 'otro', 'Grifo del baño');
        $this->loginAs($w['rita'], $w['centre']);

        $text = $this->rows($w);

        self::assertSame(1, $this->rowCount($w));
        self::assertStringContainsString('#1', $text);
        self::assertStringNotContainsString('#2', $text);
    }

    public function testAResponsibleSeesTheIssuesOfTheirTypeAndItsDescendants(): void
    {
        $w = $this->makeWorld();
        $this->report($w, 'rita', 'Una', $w['electricidad']);
        $this->report($w, 'otro', 'Otra', $w['mantenimiento']);
        $this->report($w, 'otro', 'De otro árbol', $w['limpieza']);

        // Luis gestiona «Mantenimiento», del que cuelga «Electricidad».
        $this->loginAs($w['luis'], $w['centre']);
        self::assertSame(2, $this->rowCount($w));

        // Ana solo gestiona «Electricidad».
        $this->loginAs($w['ana'], $w['centre']);
        self::assertSame(1, $this->rowCount($w));

        $this->loginAs($w['admin'], $w['centre']);
        self::assertSame(3, $this->rowCount($w));
    }

    public function testByDefaultOnlyOpenIssuesAreListedAndClosedOnesCanBeShown(): void
    {
        $w      = $this->makeWorld();
        $open   = $this->report($w, 'rita', 'Abierta');
        $closed = $this->report($w, 'rita', 'Resuelta');
        $closed->setStatus(IssueStatus::Solved);
        $this->flush();
        $this->loginAs($w['rita'], $w['centre']);

        self::assertSame(1, $this->rowCount($w));
        self::assertSame(1, $this->rowCount($w, ['status' => 'closed']));
        self::assertSame(2, $this->rowCount($w, ['status' => 'all']));
        self::assertSame(1, $this->rowCount($w, ['status' => 'solved']));
        self::assertNotSame($open->getId()->toRfc4122(), $closed->getId()->toRfc4122());
    }

    public function testSearchByNumberAndByText(): void
    {
        $w = $this->makeWorld();
        $this->report($w, 'rita', 'Se funde la bombilla');
        $this->report($w, 'rita', 'El proyector no enciende');
        $this->loginAs($w['rita'], $w['centre']);

        self::assertSame(1, $this->rowCount($w, ['search' => '#2']));
        self::assertStringContainsString('#2', $this->rows($w, ['search' => '2']));
        self::assertStringNotContainsString('#1', $this->rows($w, ['search' => '2']));
        self::assertSame(1, $this->rowCount($w, ['search' => 'bombilla']));
        self::assertSame(2, $this->rowCount($w, ['search' => 'Rita']), 'también por quien la registró');
        self::assertSame(0, $this->rowCount($w, ['search' => 'inexistente']));
    }

    public function testFilterByTypeIncludesTheDescendantsAndByPriority(): void
    {
        $w   = $this->makeWorld();
        $one = $this->report($w, 'rita', 'Eléctrica', $w['electricidad']);
        $this->report($w, 'rita', 'Genérica', $w['mantenimiento']);
        $this->report($w, 'rita', 'De limpieza', $w['limpieza']);
        $this->setPriority($one, $w, 'Alta');
        $this->loginAs($w['admin'], $w['centre']);

        self::assertSame(2, $this->rowCount($w, ['typeId' => $w['mantenimiento']->getId()->toRfc4122()]));
        self::assertSame(1, $this->rowCount($w, ['typeId' => $w['electricidad']->getId()->toRfc4122()]));
        self::assertSame(1, $this->rowCount($w, ['priorityId' => $this->priority($w, 'Alta')->getId()->toRfc4122()]));
        self::assertSame(2, $this->rowCount($w, ['priorityId' => 'none']));
    }

    public function testOverdueFilterAndStatisticsForAResponsible(): void
    {
        $w   = $this->makeWorld();
        $one = $this->report($w, 'rita', 'Vencida');
        $this->report($w, 'rita', 'Reciente');
        $this->setPriority($one, $w, 'Crítica');
        $one->setMaxDue(new \DateTimeImmutable('2020-01-10'));
        $this->flush();
        $this->loginAs($w['admin'], $w['centre']);

        self::assertSame(1, $this->rowCount($w, ['overdueOnly' => true]));

        $stats = self::getContainer()->get(\App\Repository\IssueRepository::class)->statistics($w['centre'], $w['admin'], true, [], new \DateTimeImmutable('2026-11-06'));
        self::assertSame(['open' => 2, 'in_progress' => 0, 'no_priority' => 1, 'overdue' => 1, 'closed' => 0], $stats);
    }

    public function testSortingByPutsUnprioritisedIssuesLast(): void
    {
        $w   = $this->makeWorld();
        $a   = $this->report($w, 'rita', 'Sin prioridad');
        $b   = $this->report($w, 'rita', 'Con prioridad crítica');
        $this->setPriority($b, $w, 'Crítica');
        $this->loginAs($w['admin'], $w['centre']);

        $component = $this->createLiveComponent('IssueListComponent', ['centre' => $w['centre']], $this->client);
        $text      = $component->call('sortBy', ['column' => 'priority'])->render()->crawler()->filter('tbody')->text();

        self::assertLessThan(strpos($text, 'Sin prioridad'), strpos($text, 'Con prioridad crítica'));
        self::assertNotSame($a->getId()->toRfc4122(), $b->getId()->toRfc4122());
    }

    public function testBulkStatusChangeAppliesWhatIsAllowedAndCountsTheRest(): void
    {
        $w   = $this->makeWorld();
        $one = $this->report($w, 'rita', 'Primera');
        $two = $this->report($w, 'rita', 'Segunda');
        $this->setPriority($one, $w, 'Alta');
        $this->loginAs($w['ana'], $w['centre']);

        $component = $this->createLiveComponent('IssueListComponent', ['centre' => $w['centre']], $this->client);
        $component->set('selected', [$one->getId()->toRfc4122(), $two->getId()->toRfc4122()]);
        $render = $component->call('bulkStatus', ['to' => 'in_progress'])->render();

        $this->em->clear();
        self::assertSame(IssueStatus::InProgress, $this->em->getRepository(Issue::class)->find($one->getId())?->getStatus());
        self::assertSame(IssueStatus::Open, $this->em->getRepository(Issue::class)->find($two->getId())?->getStatus(), 'sin prioridad no pasa a «en proceso»');
        self::assertStringContainsString('Cambiadas: 1. Sin cambiar: 1', $render->crawler()->text());
    }

    public function testAReporterCannotChangeStatusInBulk(): void
    {
        $w   = $this->makeWorld();
        $one = $this->report($w, 'rita', 'Mía');
        $this->loginAs($w['rita'], $w['centre']);

        $component = $this->createLiveComponent('IssueListComponent', ['centre' => $w['centre']], $this->client);
        $component->set('selected', [$one->getId()->toRfc4122()])->call('bulkStatus', ['to' => 'solved']);

        $this->em->clear();
        self::assertSame(IssueStatus::Open, $this->em->getRepository(Issue::class)->find($one->getId())?->getStatus());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $w
     * @param array<string, mixed> $props
     */
    private function rows(array $w, array $props = []): string
    {
        $component = $this->createLiveComponent('IssueListComponent', ['centre' => $w['centre']], $this->client);
        foreach ($props as $name => $value) {
            $component->set($name, $value);
        }
        $crawler = $component->render()->crawler();

        return $crawler->filter('tbody')->count() > 0 ? $crawler->filter('tbody')->text() : '';
    }

    /**
     * @param array<string, mixed> $w
     * @param array<string, mixed> $props
     */
    private function rowCount(array $w, array $props = []): int
    {
        $component = $this->createLiveComponent('IssueListComponent', ['centre' => $w['centre']], $this->client);
        foreach ($props as $name => $value) {
            $component->set($name, $value);
        }

        return $component->render()->crawler()->filter('tbody tr')->count();
    }

    /**
     * @param array<string, mixed> $w
     */
    private function report(array $w, string $who, string $description, ?IssueType $type = null): Issue
    {
        return self::getContainer()->get(IssueManager::class)->create($w['centre'], $w['year'], $w[$who], $type ?? $w['electricidad'], null, null, "<p>$description</p>", []);
    }

    /** @param array<string, mixed> $w */
    private function setPriority(Issue $issue, array $w, string $name): void
    {
        self::getContainer()->get(IssueManager::class)->setPriority($issue, $w['admin'], $this->priority($w, $name));
    }

    /** @param array<string, mixed> $w */
    private function priority(array $w, string $name): IssuePriority
    {
        $found = $this->em->getRepository(IssuePriority::class)->findOneBy(['educationalCentre' => $w['centre'], 'name' => $name]);
        self::assertNotNull($found);

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeWorld(): array
    {
        $seq    = ++self::$seq;
        $admin  = (new Teacher(new PersonName('Admin', 'Listado')))->setUsername("admin.list.$seq")->setAdmin(true);
        $ana    = (new Teacher(new PersonName('Ana', 'Responsable')))->setUsername("ana.list.$seq");
        $luis   = (new Teacher(new PersonName('Luis', 'Raíz')))->setUsername("luis.list.$seq");
        $rita   = (new Teacher(new PersonName('Rita', 'Informante')))->setUsername("rita.list.$seq");
        $otro   = (new Teacher(new PersonName('Otro', 'Docente')))->setUsername("otro.list.$seq");
        $centre = (new EducationalCentre())->setCode("4600{$seq}66")->setName("IES Listado $seq")->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        foreach ([$ana, $luis, $rita, $otro] as $teacher) {
            $year->addTeacher($teacher);
        }
        $mantenimiento = (new IssueType())->setEducationalCentre($centre)->setName('Mantenimiento')->addManager($luis);
        $electricidad  = (new IssueType())->setEducationalCentre($centre)->setName('Electricidad')->setParent($mantenimiento)->addManager($ana);
        $limpieza      = (new IssueType())->setEducationalCentre($centre)->setName('Limpieza');

        $this->persist($admin, $ana, $luis, $rita, $otro, $centre, $year, $mantenimiento, $electricidad, $limpieza);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        self::getContainer()->get(IssuePrioritySeeder::class)->seedForCentre($centre);
        $this->flush();

        return compact('admin', 'ana', 'luis', 'rita', 'otro', 'centre', 'year', 'mantenimiento', 'electricidad', 'limpieza');
    }
}
