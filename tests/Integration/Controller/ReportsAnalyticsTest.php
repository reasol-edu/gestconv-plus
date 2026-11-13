<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/** Pantalla «Analítica de convivencia» (gráficos) de Informes. */
class ReportsAnalyticsTest extends ControllerTestCase
{
    private static int $counter = 0;
    private int $reportNumber = 0;

    // ── acceso ────────────────────────────────────────────────────────────────

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $w = $this->world();

        $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAnUnprivilegedTeacherIsForbidden(): void
    {
        $w       = $this->world();
        $teacher = $this->teacher('plain');
        $this->persist($teacher);
        $this->loginAs($teacher, $w['centre']);

        $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAdminOfAnotherCentreIsForbidden(): void
    {
        $mine  = $this->world();
        $other = $this->world();
        $this->loginAs($mine['centreAdmin'], $mine['centre']);

        $this->client->request('GET', $this->url($other['centre']));

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheHubOffersTheAnalytics(): void
    {
        $w = $this->world();
        $this->loginAs($w['centreAdmin'], $w['centre']);

        $this->client->request('GET', '/centro/' . $w['centre']->getId()->toRfc4122() . '/informes');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href$="/informes/analitica"]');
    }

    // ── contenido ─────────────────────────────────────────────────────────────

    public function testWithoutRangeItShowsTheWholeYearWithEveryChart(): void
    {
        $w = $this->world();
        $this->makeReports($w, 6);
        $this->loginAs($w['centreAdmin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Analítica de convivencia');
        // Evolución mensual, día, hora y sanciones: gráficos SVG accesibles; el resto, barras con su tabla de datos.
        self::assertSelectorCount(4, 'svg[role="img"][aria-label]');
        self::assertSelectorExists('dl dd');
        self::assertSelectorCount(7, 'details table');
        // Los meses salen de las traducciones del calendario, no de texto fijo en el código.
        self::assertStringContainsString('Mar', (string) $this->client->getResponse()->getContent());
        // El rango por defecto del formulario es el del curso (2025-2026, ya terminado: del 15/09/2025 al 14/09/2026).
        self::assertSelectorExists('input[name="from"][value="2025-09-15"]');
        self::assertSelectorExists('input[name="to"][value="2026-09-14"]');
    }

    public function testTheDefaultRangeFollowsTheDatesConfiguredInTheYear(): void
    {
        $w = $this->world();
        $w['year']->setDates(new \DateTimeImmutable('2025-09-08'), new \DateTimeImmutable('2026-06-30'));
        $this->makeReports($w, 3);
        $this->flush();
        $this->loginAs($w['centreAdmin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']));

        self::assertSelectorExists('input[name="from"][value="2025-09-08"]');
        self::assertSelectorExists('input[name="to"][value="2026-06-30"]');
    }

    public function testTheOtherReportsOfferTheWholeCourseAsAQuickRange(): void
    {
        $w = $this->world();
        $this->loginAs($w['centreAdmin'], $w['centre']);

        foreach (['estadisticas-grupo', 'salidas', 'incidencias'] as $report) {
            $this->client->request('GET', '/centro/' . $w['centre']->getId()->toRfc4122() . '/informes/' . $report);

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href*="from=2025-09-15"][href*="to=2026-09-14"]');
        }
    }

    public function testARangeWithoutReportsExplainsItsEmptiness(): void
    {
        $w = $this->world();
        $this->makeReports($w, 3);
        $this->loginAs($w['centreAdmin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']) . '?from=2024-01-01&to=2024-02-01');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('svg[role="img"]');
        self::assertSelectorTextContains('main, body', 'No hay partes ni sanciones en el rango');
    }

    public function testAYearWithoutReportsSaysSo(): void
    {
        $w = $this->world();
        $this->loginAs($w['centreAdmin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('svg[role="img"]');
        self::assertSelectorTextContains('main, body', 'Todavía no hay partes registrados');
    }

    public function testNamesAreEscapedInTheChartsAndTables(): void
    {
        $w = $this->world();
        $w['group']->setName('<b>1ºX</b>');
        $this->makeReports($w, 1);
        $this->flush();
        $this->loginAs($w['centreAdmin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<b>1ºX</b>', $html);
        self::assertStringContainsString('&lt;b&gt;1ºX&lt;/b&gt;', $html);
    }

    // ── consultas ─────────────────────────────────────────────────────────────

    public function testQueryCountDoesNotGrowWithTheNumberOfReports(): void
    {
        // Dos centros: uno con un parte y otro con 31 (grupos, cursos, estudiantes y conductas distintos).
        $small = $this->world();
        $this->makeReports($small, 1);
        $big = $this->world();
        $this->makeReports($big, 31, true);

        $this->loginAs($small['centreAdmin'], $small['centre']);
        $few = $this->countQueries($this->url($small['centre']));

        $this->loginAs($big['centreAdmin'], $big['centre']);
        $many = $this->countQueries($this->url($big['centre']));

        self::assertLessThanOrEqual($few, $many, "La analítica hace $many consultas con 31 partes y $few con 1: crece con los datos.");
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function url(EducationalCentre $centre): string
    {
        return '/centro/' . $centre->getId()->toRfc4122() . '/informes/analitica';
    }

    private function teacher(string $name): Teacher
    {
        return (new Teacher(new PersonName('Test', ucfirst($name))))->setUsername($name . '.' . uniqid('', false))->setPassword('x');
    }

    /**
     * Crea `$count` partes en marzo de 2026, con estudiantes, grupos y conductas distintos si `$varied`.
     *
     * @param array<string, mixed> $w
     */
    private function makeReports(array $w, int $count, bool $varied = false): void
    {
        $centre = $w['centre'];
        $teacher = $w['centreAdmin'];
        $category = (new IncidentBehaviorCategory())->setEducationalCentre($centre)->setName('Cat ' . uniqid('', false))->setSerious($varied)->setPosition(0);
        $this->persist($category);

        for ($i = 0; $i < $count; $i++) {
            $group = $w['group'];
            if ($varied) {
                $course = (new Course())->setName('Curso ' . $i)->setAcademicYear($w['year']);
                $group  = (new Group())->setName('G' . $i)->setCourse($course);
                $this->persist($course, $group);
            }
            $author = $teacher;
            if ($varied) {
                // Un autor distinto por parte: así se nota si algo lee al autor sin haberlo cargado junto a los partes.
                $author = $this->teacher('autor' . $i);
                $this->persist($author);
            }
            $student   = (new Student(new PersonName('Est' . $i, 'Apellido')))->setStudentId('NIE-ana-' . (++self::$counter));
            $student->addGroup($group);
            $behaviour = (new IncidentBehavior())->setEducationalCentre($centre)->setCategory($category)->setName('Conducta ' . $i . '-' . uniqid('', false))->setPosition($i)->setActive(true);
            $report    = (new IncidentReport())
                ->setAcademicYear($w['year'])
                ->setNumber(++$this->reportNumber)
                ->setStudent($student)
                ->setGroup($group)
                ->setRegisteredBy($author)
                ->setOccurredAt(new \DateTimeImmutable(sprintf('2026-03-%02d 1%d:00', 2 + $i % 25, $i % 6)))
                ->setDescription('<p>Test</p>')
                ->setExpelledFromClass(false);
            $report->addBehavior($behaviour);
            $this->persist($student, $behaviour, $report);
        }
    }

    /** @return array{centre: EducationalCentre, year: AcademicYear, group: Group, centreAdmin: Teacher} */
    private function world(): array
    {
        $n          = ++self::$counter;
        $centre     = (new EducationalCentre())->setCode('8' . str_pad((string) $n, 7, '0', STR_PAD_LEFT))->setName('IES Analítica ' . $n)->setCity('Sevilla');
        $year       = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course     = (new Course())->setName('DAW')->setAcademicYear($year);
        $group      = (new Group())->setName('1ºA')->setCourse($course);
        $admin      = $this->teacher('director');
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        $this->persist($centre, $year, $course, $group, $admin);

        return ['centre' => $centre, 'year' => $year, 'group' => $group, 'centreAdmin' => $admin];
    }
}
