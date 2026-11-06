<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\IssueManager;
use App\Service\IssuePrioritySeeder;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/** Dónde aparecen las incidencias fuera de su módulo: campana, panel de inicio, búsqueda global y menú. */
class IssueSurfacesTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;

    private static int $seq = 0;

    protected function setUp(): void
    {
        self::mockTime('2026-11-09 08:00:00');
        parent::setUp();
    }

    public function testTheBellListsNewIssuesToAttendAndOverdueOnesForAResponsible(): void
    {
        $w = $this->makeWorld();
        $this->report($w, 'Sin luz');
        $late = $this->report($w, 'Vencida');
        $late->setMaxDue(new \DateTimeImmutable('2026-11-02'));
        $this->flush();
        $this->loginAs($w['ana'], $w['centre']);

        $text = $this->bellText();

        self::assertStringContainsString('Incidencias por atender', $text);
        self::assertStringContainsString('Incidencias vencidas', $text);
        self::assertStringContainsString('Rita Informante ha registrado la incidencia #1', $text);
    }

    public function testTheBellShowsTheReporterRecentNewsButNotAnIssueNobodyHasTouched(): void
    {
        $w     = $this->makeWorld();
        $quiet = $this->report($w, 'Sin novedades');
        $done  = $this->report($w, 'Resuelta');
        $this->em->clear();
        $manager = self::getContainer()->get(IssueManager::class);
        $manager->transition($this->em->getRepository(Issue::class)->find($done->getId()), $this->em->getRepository(Teacher::class)->find($w['ana']->getId()), IssueStatus::Solved);
        $this->loginAs($w['rita'], $w['centre']);

        $text = $this->bellText();

        self::assertStringContainsString('Novedades en tus incidencias', $text);
        self::assertStringContainsString('Tu incidencia #2', $text);
        self::assertStringNotContainsString('Tu incidencia #1', $text);
        self::assertStringNotContainsString('Incidencias por atender', $text, 'quien solo informa no gestiona');
        self::assertNotSame($quiet->getId()->toRfc4122(), $done->getId()->toRfc4122());
    }

    public function testTheDashboardOffersToReportAndShowsWhatIsPending(): void
    {
        $w = $this->makeWorld();
        $this->report($w, 'Sin luz');
        $this->loginAs($w['ana'], $w['centre']);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#quick-actions a[href="/incidencias/nueva"]'));
        self::assertStringContainsString('Incidencias por atender', $crawler->filter('#quick-actions')->text());
    }

    public function testTheGlobalSearchFindsOwnAndManagedIssuesOnly(): void
    {
        $w = $this->makeWorld();
        $this->report($w, 'El proyector no enciende');

        $this->loginAs($w['ana'], $w['centre']);
        $this->client->request('GET', '/buscar?q=proyector');
        $groups = json_decode((string) $this->client->getResponse()->getContent(), true)['groups'];
        self::assertArrayHasKey('issues', $groups);
        self::assertStringContainsString('#1', $groups['issues'][0]['label']);

        $this->loginAs($w['otro'], $w['centre']);
        $this->client->request('GET', '/buscar?q=proyector');
        self::assertArrayNotHasKey('issues', json_decode((string) $this->client->getResponse()->getContent(), true)['groups']);
    }

    public function testTheMenuAndTheQuickActionsOfferIssuesWhileTheModuleIsOn(): void
    {
        $w = $this->makeWorld();
        $this->loginAs($w['rita'], $w['centre']);

        $crawler = $this->client->request('GET', '/');

        self::assertGreaterThan(0, $crawler->filter('nav a[href="/incidencias"]')->count());
        self::assertStringContainsString('Nueva incidencia', (string) $crawler->filter('[data-command-palette-actions-value]')->attr('data-command-palette-actions-value'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function bellText(): string
    {
        return $this->createLiveComponent('NotificationBellComponent', [], $this->client)->render()->crawler()->text();
    }

    /** @param array<string, mixed> $w */
    private function report(array $w, string $description): Issue
    {
        return self::getContainer()->get(IssueManager::class)->create($w['centre'], $w['year'], $w['rita'], $w['electricidad'], null, null, "<p>$description</p>", []);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeWorld(): array
    {
        $seq    = ++self::$seq;
        $admin  = (new Teacher(new PersonName('Admin', 'Superficies')))->setUsername("admin.surf.$seq")->setAdmin(true);
        $ana    = (new Teacher(new PersonName('Ana', 'Responsable')))->setUsername("ana.surf.$seq");
        $rita   = (new Teacher(new PersonName('Rita', 'Informante')))->setUsername("rita.surf.$seq");
        $otro   = (new Teacher(new PersonName('Otro', 'Docente')))->setUsername("otro.surf.$seq");
        $centre = (new EducationalCentre())->setCode("4800{$seq}44")->setName("IES Superficies $seq")->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        foreach ([$ana, $rita, $otro] as $teacher) {
            $year->addTeacher($teacher);
        }
        $electricidad = (new IssueType())->setEducationalCentre($centre)->setName('Electricidad')->addManager($ana);

        $this->persist($admin, $ana, $rita, $otro, $centre, $year, $electricidad);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        self::getContainer()->get(IssuePrioritySeeder::class)->seedForCentre($centre);
        $this->flush();

        return compact('admin', 'ana', 'rita', 'otro', 'centre', 'year', 'electricidad');
    }
}
