<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\IncidentReportObservation;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ensures that write operations return 403 when an admin is viewing a
 * non-active academic year, and that write-action buttons are absent from
 * the corresponding index pages in that mode.
 */
class PastYearGuardTest extends ControllerTestCase
{
    // ── CentreTeacherController ───────────────────────────────────────────────

    #[DataProvider('provideCentreTeacherWriteRoutes')]
    public function testCentreTeacherWriteReturns403WhenViewingPastYear(string $method, string $pathSuffix): void
    {
        [$admin, $centre, , $pastYear] = $this->makeCentreWithTwoYears();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request($method, '/centro/' . $centre->getId()->toRfc4122() . $pathSuffix);

        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideCentreTeacherWriteRoutes(): iterable
    {
        // Since the 403 guard fires before entity lookups, fake entity UUIDs suffice.
        $fakeId = '00000000-0000-0000-0000-000000000001';

        yield 'add POST'                       => ['POST', '/docentes-curso/a%C3%B1adir'];
        yield 'import GET'                     => ['GET',  '/docentes-curso/importar'];
        yield 'import_assignments GET'         => ['GET',  '/docentes-curso/importar-asignaciones'];
        yield 'register GET'                   => ['GET',  '/docentes-curso/registrar'];
        yield 'subjects GET'                   => ['GET',  "/docentes-curso/{$fakeId}/materias"];
        yield 'remove POST'                    => ['POST', "/docentes-curso/{$fakeId}/quitar"];
    }

    public function testCentreTeacherIndexHidesWriteButtonsWhenViewingPastYear(): void
    {
        [$admin, $centre, $activeYear, $pastYear] = $this->makeCentreWithTwoYears();
        $teacher = (new Teacher(new PersonName('Test', 'Docente')))->setUsername('teacher.past.1');
        $activeYear->addTeacher($teacher);
        $this->persist($teacher);
        $this->flush();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $crawler = $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/docentes-curso');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/importar"]');
        self::assertSelectorNotExists('a[href*="/registrar"]');
        self::assertSelectorNotExists('form[action*="/a%C3%B1adir"], form[action*="/añadir"]');
    }

    // ── StudentController ─────────────────────────────────────────────────────

    #[DataProvider('provideStudentWriteRoutes')]
    public function testStudentWriteReturns403WhenViewingPastYear(string $method, string $pathSuffix): void
    {
        [$admin, $centre, , $pastYear] = $this->makeCentreWithTwoYears();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request($method, '/centro/' . $centre->getId()->toRfc4122() . '/estudiantes' . $pathSuffix);

        self::assertResponseStatusCodeSame(403);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideStudentWriteRoutes(): iterable
    {
        $fakeId = '00000000-0000-0000-0000-000000000001';

        yield 'new GET'      => ['GET',  '/nuevo'];
        yield 'edit GET'     => ['GET',  "/{$fakeId}/editar"];
        yield 'import GET'   => ['GET',  '/importar'];
        yield 'delete POST'  => ['POST', "/{$fakeId}/eliminar"];
    }

    public function testStudentIndexHidesWriteButtonsWhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear] = $this->makeCentreWithTwoYears();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $crawler = $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/estudiantes');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/nuevo"]');
        self::assertSelectorNotExists('a[href*="/importar"]');
    }

    // ── DashboardController ───────────────────────────────────────────────────

    public function testDashboardHidesWriteActionsWhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear] = $this->makeCentreWithTwoYears();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Importar estudiantes', $crawler->html());
    }

    public function testDashboardShowsViewedYearNameInAmberWhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear] = $this->makeCentreWithTwoYears();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($pastYear->getName(), $crawler->html());
    }

    // ── Sanity: same routes succeed when NOT viewing a past year ──────────────

    public function testCentreTeacherWriteSucceedsWhenViewingActiveYear(): void
    {
        [$admin, $centre] = $this->makeCentreWithTwoYears();
        $this->loginAs($admin, $centre);
        // No viewPastYear() call — session has no year override

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/docentes-curso/registrar');

        self::assertResponseIsSuccessful();
    }

    public function testStudentWriteSucceedsWhenViewingActiveYear(): void
    {
        [$admin, $centre] = $this->makeCentreWithTwoYears();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/estudiantes/nuevo');

        self::assertResponseIsSuccessful();
    }

    // ── IncidentReportController ────────────────────────────────────────────────

    public function testIncidentNewReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request('GET', '/partes/nuevo');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIncidentEditReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, $report] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIncidentDeleteReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, $report] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request('POST', '/partes/' . $report->getId()->toRfc4122() . '/eliminar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIncidentAddObservationReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, $report] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request('POST', '/partes/' . $report->getId()->toRfc4122() . '/observaciones');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIncidentEditObservationReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, $report, , $observation] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request(
            'GET',
            '/partes/' . $report->getId()->toRfc4122() . '/observaciones/' . $observation->getId()->toRfc4122() . '/editar',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testIncidentDeleteObservationReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, $report, , $observation] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request(
            'POST',
            '/partes/' . $report->getId()->toRfc4122() . '/observaciones/' . $observation->getId()->toRfc4122() . '/eliminar',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testIncidentIndexHidesNewButtonWhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $crawler = $this->client->request('GET', '/partes');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href$="/partes/nuevo"]');
    }

    public function testIncidentShowHidesWriteButtonsWhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, $report] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $crawler = $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href$="/editar"]');
        self::assertSelectorNotExists('a[href*="/notificaciones/partes/"]');
        self::assertSelectorNotExists('form[action$="/eliminar"]');
    }

    public function testIncidentNewSucceedsWhenViewingActiveYear(): void
    {
        [$admin, $centre] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/partes/nuevo');

        self::assertResponseIsSuccessful();
    }

    // ── SanctionController ───────────────────────────────────────────────────────

    public function testSanctionNewReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request('GET', '/sanciones/nueva');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSanctionEditReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, , $sanction] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSanctionDeleteReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, , $sanction] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request('POST', '/sanciones/' . $sanction->getId()->toRfc4122() . '/eliminar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSanctionIndexHidesNewButtonWhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $crawler = $this->client->request('GET', '/sanciones');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href$="/sanciones/nueva"]');
    }

    public function testSanctionShowHidesWriteButtonsWhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, , $sanction] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $crawler = $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href$="/editar"]');
        self::assertSelectorNotExists('a[href*="/notificaciones/sanciones/"]');
        self::assertSelectorNotExists('form[action$="/eliminar"]');
    }

    public function testSanctionEditSucceedsWhenViewingActiveYear(): void
    {
        [$admin, $centre, , , , $sanction] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/editar');

        self::assertResponseIsSuccessful();
    }

    // ── NotificationController ───────────────────────────────────────────────────

    public function testNotificationRegisterReportReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, $report] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request('GET', '/notificaciones/partes/' . $report->getId()->toRfc4122() . '/registrar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNotificationRegisterSanctionReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear, , $sanction] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $this->client->request('GET', '/notificaciones/sanciones/' . $sanction->getId()->toRfc4122() . '/registrar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNotificationRegisterStudentReportsReturns403WhenViewingPastYear(): void
    {
        [$admin, $centre, , $pastYear] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);
        $this->viewPastYear($pastYear);

        $fakeId = '00000000-0000-0000-0000-000000000001';
        $this->client->request('GET', '/notificaciones/partes/estudiante/' . $fakeId . '/registrar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNotificationRegisterReportSucceedsWhenViewingActiveYear(): void
    {
        [$admin, $centre, , , $report] = $this->makeCentreWithReportAndSanction();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/notificaciones/partes/' . $report->getId()->toRfc4122() . '/registrar');

        self::assertResponseIsSuccessful();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returns a centre admin, a centre, the active year, and a past (non-active) year.
     *
     * @return array{Teacher, EducationalCentre, AcademicYear, AcademicYear}
     */
    private function makeCentreWithTwoYears(): array
    {
        static $seq = 0;
        $seq++;

        $admin      = (new Teacher(new PersonName('Admin', 'Guard')))->setUsername("admin.guard.{$seq}")->setAdmin(true);
        $centre     = (new EducationalCentre())->setCode("4100{$seq}999")->setName("IES Guard {$seq}")->setCity('Sevilla');
        $activeYear = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $pastYear   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);

        $this->persist($admin, $centre, $activeYear, $pastYear);
        $centre->setActiveAcademicYear($activeYear);
        $centre->addAdmin($admin);
        $this->flush();

        return [$admin, $centre, $activeYear, $pastYear];
    }

    /**
     * Like makeCentreWithTwoYears(), plus a group/student and one incident report
     * and one sanction registered in the active year, for testing guards on
     * actions that need an existing entity to look up.
     *
     * @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear, 3: AcademicYear, 4: IncidentReport, 5: Sanction, 6: IncidentReportObservation}
     */
    private function makeCentreWithReportAndSanction(): array
    {
        [$admin, $centre, $activeYear, $pastYear] = $this->makeCentreWithTwoYears();

        $course    = (new Course())->setName('DAW')->setAcademicYear($activeYear);
        $group     = (new Group())->setName('1ºA')->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . uniqid('', false));
        $group->addStudent($student);

        $category = (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)->setName('Contrarias')->setSerious(false)->setPosition(0);
        $behavior = (new IncidentBehavior())
            ->setEducationalCentre($centre)->setCategory($category)->setName('Perturbación')->setPosition(0)->setActive(true);

        $report = (new IncidentReport())
            ->setAcademicYear($activeYear)
            ->setNumber(1)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($admin)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test.</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);

        $sanction = (new Sanction())
            ->setAcademicYear($activeYear)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($admin)
            ->setDetails('Detalle de la sanción.')
            ->setNoMeasureApplied(true)
            ->setNoMeasureReason('Sin medida aplicada.');

        $this->persist($course, $group, $student, $category, $behavior, $report, $sanction);
        $this->flush();

        $observation = new IncidentReportObservation($report, $admin, new \DateTimeImmutable(), 'Observación de prueba.');
        $this->persist($observation);
        $this->flush();

        return [$admin, $centre, $activeYear, $pastYear, $report, $sanction, $observation];
    }
}
