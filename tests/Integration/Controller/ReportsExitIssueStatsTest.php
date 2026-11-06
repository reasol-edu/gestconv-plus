<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Entity\GlobalSettingValue;
use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\IssueChange;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\StudentExit;
use App\Entity\Teacher;
use App\Service\ExitStatisticsService;
use App\Service\IssueStatisticsService;
use App\Tests\Integration\ControllerTestCase;

/** Los informes de salidas del aula y de incidencias: cifras, exportaciones y permisos. */
class ReportsExitIssueStatsTest extends ControllerTestCase
{
    private static int $seq = 0;

    // ── Salidas ───────────────────────────────────────────────────────────────

    public function testTheExitReportBreaksDownByReasonGroupAndStudent(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $report = self::getContainer()->get(ExitStatisticsService::class)->build($w['centre'], $w['year'], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));

        // 5 salidas dentro del rango (la del 1 de septiembre queda fuera): 3 de Servicio, 2 de Enfermería.
        self::assertSame(5, $report->totals->exits);
        self::assertSame(3, $report->totals->students);
        self::assertSame(2, $report->totals->groups);
        self::assertSame(1, $report->totals->notReturned());
        self::assertSame(4, $report->totals->returned);
        // 5 + 10 + 15 + 30 = 60 min en 4 salidas con regreso.
        self::assertSame(900, $report->totals->averageSeconds());
        self::assertSame(1800, $report->totals->secondsMax);

        self::assertSame(['Servicio', 'Enfermería'], array_map(static fn ($r): string => $r->reason->getName(), $report->reasons));
        self::assertSame(3, $report->reasons[0]->totals->exits);
        self::assertSame('Ana Ruiz', $report->reasons[0]->topStudents[0]->student->getName()->full());
        self::assertSame(2, $report->reasons[0]->topStudents[0]->exits);

        // Por grupo: 1ºA tiene 3 salidas; 1ºB, 2; todos los grupos del curso aparecen.
        $rows = [];
        foreach ($report->courses as $course) {
            foreach ($course->rows as $row) {
                $rows[$row->group?->getName() ?? ''] = $row->totals->exits;
            }
        }
        self::assertSame(['1ºA' => 3, '1ºB' => 2, '1ºC' => 0], $rows);

        self::assertSame('Ana Ruiz', $report->topStudents[0]->student->getName()->full());
        self::assertSame($w['servicio']->getId()->toRfc4122(), $report->topStudents[0]->topReason?->getId()->toRfc4122());
    }

    public function testTheExitReportPageAndItsExports(): void
    {
        $w   = $this->world();
        $url = '/centro/' . $w['centre']->getId()->toRfc4122() . '/informes/salidas';
        $this->loginAs($w['admin'], $w['centre']);

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table'), 'sin rango no hay informe');

        $crawler = $this->client->request('GET', $url . '?from=2026-10-01&to=2026-10-31');
        self::assertResponseIsSuccessful();
        $text = $crawler->text();
        self::assertStringContainsString('Por tipo de salida', $text);
        self::assertStringContainsString('Servicio', $text);
        self::assertStringContainsString('Enfermería', $text);
        self::assertStringContainsString('Ana Ruiz', $text);
        self::assertStringContainsString('15 min', $text, 'tiempo medio');

        $this->client->request('GET', $url . '/pdf?from=2026-10-01&to=2026-10-31');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', $url . '/excel?from=2026-10-01&to=2026-10-31');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testTheExitReportWithoutExitsSaysSo(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $crawler = $this->client->request('GET', '/centro/' . $w['centre']->getId()->toRfc4122() . '/informes/salidas?from=2025-01-01&to=2025-01-31');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No hay salidas del aula', $crawler->text());
    }

    public function testTheReportsAreForCentreAdminsAndFollowTheirModule(): void
    {
        $w        = $this->world();
        $teacher  = (new Teacher(new PersonName('Doc', 'Normal')))->setUsername('doc.stats.' . ++self::$seq);
        $this->persist($teacher);
        $base = '/centro/' . $w['centre']->getId()->toRfc4122() . '/informes';

        $this->loginAs($teacher, $w['centre']);
        $this->client->request('GET', $base . '/salidas');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $base . '/incidencias');
        self::assertResponseStatusCodeSame(403);

        // Con el módulo de salidas desactivado, el informe de salidas desaparece (y su tarjeta).
        $this->loginAs($w['admin'], $w['centre']);
        $crawler = $this->client->request('GET', $base);
        self::assertCount(1, $crawler->filter('a[href$="/informes/salidas"]'));
        self::assertCount(1, $crawler->filter('a[href$="/informes/incidencias"]'));

        $this->switchOff('exits');
        $crawler = $this->client->request('GET', $base);
        self::assertCount(0, $crawler->filter('a[href$="/informes/salidas"]'));
        self::assertCount(1, $crawler->filter('a[href$="/informes/incidencias"]'));
        $this->client->request('GET', $base . '/salidas');
        self::assertResponseStatusCodeSame(404);
    }

    // ── Incidencias ───────────────────────────────────────────────────────────

    public function testTheIssueReportComputesClosureTimesAndDeadlines(): void
    {
        $w      = $this->world();
        $report = self::getContainer()->get(IssueStatisticsService::class)->build($w['centre'], $w['year'], new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));

        $t = $report->totals;
        self::assertSame(5, $t->registered);
        self::assertSame(2, $t->solved);
        self::assertSame(1, $t->notApplicable);
        self::assertSame(0, $t->duplicate);
        self::assertSame(2, $t->open);
        self::assertSame(60.0, $t->closedPercent());
        // Cierres: 1 día, 3 días y 5 días (de a 24 h): media 3 días, mediana 3 días.
        self::assertSame(3 * 86400, $t->avgCloseSeconds);
        self::assertSame(3 * 86400, $t->medianCloseSeconds);
        // Plazo máximo: dos cerradas con plazo, una a tiempo y otra tarde; además hay 1 abierta vencida (plazo 2026-10-02).
        self::assertSame(2, $t->closedWithMaxDue);
        self::assertSame(1, $t->closedOnTimeMax);
        self::assertSame(50.0, $t->onTimeMaxPercent());
        self::assertGreaterThanOrEqual(1, $t->overdue);
        // Primera gestión: solo la incidencia con cambio de prioridad (2 h).
        self::assertSame(2 * 3600, $t->avgFirstManagementSeconds);

        self::assertSame(['Solucionada', 'No procede', 'Cerrada por duplicidad'], array_map(static fn ($r): string => $r->label, $report->byClosure));

        // Por tipo: un único tipo raíz «Mantenimiento» con dos tipos (cuatro Electricidad y una Limpieza).
        self::assertCount(2, $report->branches);
        $names = array_map(static fn ($b): string => $b->rootName, $report->branches);
        self::assertSame(['Limpieza', 'Mantenimiento'], $names);
        $maintenance = $report->branches[1];
        self::assertSame(4, $maintenance->total->registered);
        self::assertSame('Mantenimiento › Electricidad', $maintenance->rows[0]->label);

        // Por prioridad: Alta, Baja y sin prioridad.
        self::assertSame(['Alta', 'Sin prioridad'], array_map(static fn ($r): string => $r->label, $report->byPriority));

        self::assertSame('Mantenimiento › Electricidad', $report->mostFrequent[0]->label);
        self::assertNotEmpty($report->slowest);
        self::assertNotEmpty($report->monthly);
    }

    public function testTheIssueReportPageAndItsExports(): void
    {
        $w   = $this->world();
        $url = '/centro/' . $w['centre']->getId()->toRfc4122() . '/informes/incidencias';
        $this->loginAs($w['admin'], $w['centre']);

        $crawler = $this->client->request('GET', $url . '?from=2026-10-01&to=2026-10-31');
        self::assertResponseIsSuccessful();
        $text = $crawler->text();
        self::assertStringContainsString('Cómo se han cerrado', $text);
        self::assertStringContainsString('Cumplimiento de plazos', $text);
        self::assertStringContainsString('Qué problemas pesan más', $text);
        self::assertStringContainsString('Mantenimiento › Electricidad', $text);
        self::assertStringContainsString('3 d 0 h', $text, 'tiempo medio de cierre');

        $this->client->request('GET', $url . '/pdf?from=2026-10-01&to=2026-10-31');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', $url . '/excel?from=2026-10-01&to=2026-10-31');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $crawler = $this->client->request('GET', $url . '?from=2025-01-01&to=2025-01-31');
        self::assertStringContainsString('No hay incidencias registradas', $crawler->text());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function switchOff(string $feature): void
    {
        $definition = (new SettingDefinition())->setKey('features.' . $feature)->setType(SettingType::Boolean)->setCategory('settings.category.features')
            ->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($definition, (new GlobalSettingValue())->setDefinition($definition)->setValue('false'));
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $seq    = ++self::$seq;
        $admin  = (new Teacher(new PersonName('Admin', 'Informes')))->setUsername("admin.stats.$seq")->setAdmin(true);
        $tchr   = (new Teacher(new PersonName('Paula', 'Docente')))->setUsername("paula.stats.$seq");
        $centre = (new EducationalCentre())->setCode("4700{$seq}55")->setName("IES Informes $seq")->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($centre);
        $course = (new Course())->setName('1º ESO')->setAcademicYear($year);
        $ga     = (new Group())->setName('1ºA')->setCourse($course);
        $gb     = (new Group())->setName('1ºB')->setCourse($course);
        $gc     = (new Group())->setName('1ºC')->setCourse($course);
        $ana    = (new Student(new PersonName('Ana', 'Ruiz')))->setStudentId("NIE-S-A$seq");
        $luis   = (new Student(new PersonName('Luis', 'Pérez')))->setStudentId("NIE-S-B$seq");
        $eva    = (new Student(new PersonName('Eva', 'Gil')))->setStudentId("NIE-S-C$seq");
        $servicio = (new ExitReason())->setEducationalCentre($centre)->setName('Servicio')->setPosition(0);
        $enfermeria = (new ExitReason())->setEducationalCentre($centre)->setName('Enfermería')->setPosition(1);

        $this->persist($admin, $tchr, $centre, $year, $course, $ga, $gb, $gc, $ana, $luis, $eva, $servicio, $enfermeria);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        $this->flush();

        // Salidas: (alumno, grupo, motivo, salida, minutos fuera o null si no ha regresado).
        $exits = [
            [$ana,  $ga, $servicio,   '2026-10-05 10:00', 5],
            [$ana,  $ga, $servicio,   '2026-10-06 10:00', 10],
            [$luis, $gb, $servicio,   '2026-10-07 11:00', 15],
            [$eva,  $ga, $enfermeria, '2026-10-08 12:00', 30],
            [$luis, $gb, $enfermeria, '2026-10-09 09:00', null],
            [$ana,  $ga, $servicio,   '2026-09-01 10:00', 5],
        ];
        foreach ($exits as [$student, $group, $reason, $at, $minutes]) {
            $exit = (new StudentExit())->setAcademicYear($year)->setStudent($student)->setGroup($group)->setReason($reason)->setAuthorizedBy($tchr)
                ->setExitedAt(new \DateTimeImmutable($at));
            if ($minutes !== null) {
                $exit->markReturned($tchr, (new \DateTimeImmutable($at))->modify("+$minutes minutes"));
            }
            $this->em->persist($exit);
        }

        // Incidencias.
        $maintenance = (new IssueType())->setEducationalCentre($centre)->setName('Mantenimiento');
        $electricity = (new IssueType())->setEducationalCentre($centre)->setName('Electricidad')->setParent($maintenance);
        $cleaning    = (new IssueType())->setEducationalCentre($centre)->setName('Limpieza');
        $high        = (new IssuePriority())->setEducationalCentre($centre)->setName('Alta')->setPosition(0)->setRecommendedDays(2)->setMaxDays(5);
        $this->persist($maintenance, $electricity, $cleaning, $high);

        // [tipo, creada, estado, cerrada, plazo máximo, plazo recomendado, prioridad]
        $issues = [
            [$electricity, '2026-10-05 08:00', IssueStatus::Solved,        '2026-10-06 08:00', '2026-10-09', '2026-10-07', $high],
            [$electricity, '2026-10-05 08:00', IssueStatus::Solved,        '2026-10-10 08:00', '2026-10-08', '2026-10-07', $high],
            [$electricity, '2026-10-06 08:00', IssueStatus::NotApplicable, '2026-10-09 08:00', null,         null,         null],
            [$electricity, '2026-10-01 08:00', IssueStatus::Open,          null,               '2026-10-02', null,         null],
            [$cleaning,    '2026-10-12 08:00', IssueStatus::InProgress,    null,               null,         null,         null],
        ];
        foreach ($issues as $n => [$type, $created, $status, $closed, $max, $rec, $priority]) {
            $issue = (new Issue(new \DateTimeImmutable($created)))
                ->setEducationalCentre($centre)->setAcademicYear($year)->setNumber($n + 1)->setType($type)->setReporter($tchr)
                ->setDescription('<p>x</p>')->setStatus($status)->setPriority($priority)
                ->setClosedAt($closed !== null ? new \DateTimeImmutable($closed) : null)
                ->setMaxDue($max !== null ? new \DateTimeImmutable($max) : null)
                ->setRecommendedDue($rec !== null ? new \DateTimeImmutable($rec) : null);
            if ($n === 0) {
                $issue->addChange(new IssueChange($issue, $admin, 'priority', null, 'Alta', new \DateTimeImmutable($created . ' +2 hours')));
            }
            $this->em->persist($issue);
        }
        $this->flush();

        return compact('admin', 'centre', 'year', 'servicio', 'enfermeria');
    }
}
