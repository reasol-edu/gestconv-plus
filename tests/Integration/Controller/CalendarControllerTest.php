<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Activity;
use App\Entity\ActivityAttachment;
use App\Entity\CentreSettingValue;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\Sanction;
use App\Entity\SchoolEvent;
use App\Entity\SchoolEventType;
use App\Entity\SettingDefinition;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class CalendarControllerTest extends ControllerTestCase
{
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        // Miércoles fijo: evita que los tests dependan del día real de
        // ejecución (fines de semana ocultan los tramos horarios).
        self::mockTime('2024-01-10');
        parent::setUp();
    }

    public function testRedirectsToLoginWhenAnonymous(): void
    {
        $this->client->request('GET', '/calendario');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testRedirectsToCentreSelectionWithoutSelectedCentre(): void
    {
        $admin = $this->makeAdmin('calendar.nocentre');
        // Two centres so the tenant subscriber forces selection instead of auto-picking.
        $this->makeCentre('46000001');
        $this->makeCentre('46000002');
        $this->loginAs($admin);

        $this->client->request('GET', '/calendario');

        self::assertResponseRedirects('/seleccion/centro');
    }

    public function testRendersCalendarWithSelectedCentre(): void
    {
        $centre = $this->makeCentre('46000003');
        $admin  = $this->makeAdmin('calendar.ok');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
    }

    public function testCalendarShowsBoardModeLinkToAdmin(): void
    {
        $centre = $this->makeCentre('46000004');
        $admin  = $this->makeAdmin('calendar.link.admin');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/calendario/tablon', (string) $this->client->getResponse()->getContent());
    }

    public function testCalendarBoardModeLinkOpensConfirmationDialogBeforeNavigating(): void
    {
        $centre = $this->makeCentre('46000076');
        $admin  = $this->makeAdmin('calendar.link.confirm.admin');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-action="dialog#open"', $content);
        self::assertStringContainsString('salir de este modo y volver a iniciar sesión', $content);
    }

    public function testCalendarHidesBoardModeLinkFromNonAdmin(): void
    {
        $centre  = $this->makeCentre('46000005');
        $teacher = (new Teacher(new PersonName('Plain', 'Teacher')))->setUsername('calendar.link.noadmin');
        $this->persist($teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/calendario/tablon', (string) $this->client->getResponse()->getContent());
    }

    // ── Barras de sanciones ──────────────────────────────────────────────────

    public function testShowsSanctionBarToAnyTeacherRegardlessOfAuthorship(): void
    {
        $world    = $this->makeScenario();
        $creator  = (new Teacher(new PersonName('Creator', 'Teacher')))->setUsername('calendar.bar.creator');
        $viewer   = (new Teacher(new PersonName('Viewer', 'Teacher')))->setUsername('calendar.bar.viewer');
        $this->persist($creator, $viewer);

        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setDetails('<p>Expulsión por <strong>conducta grave</strong></p>')
            ->setNoMeasureApplied(true)
            ->setEffectiveFrom($this->weekdayInCurrentMonth());
        $this->persist($sanction);
        $this->notifySanction($sanction, $world['centre'], $creator);

        $this->loginAs($viewer, $world['centre']);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ana García', $content);
        self::assertStringContainsString('1ºA', $content);
        self::assertStringContainsString('Expulsión por conducta grave', $content);
        self::assertStringNotContainsString('<strong>', $content);
    }

    public function testUnifiedCalendarShowsGeneralEventToAnyTeacher(): void
    {
        $world   = $this->makeScenario();
        $viewer  = (new Teacher(new PersonName('Viewer', 'Teacher')))->setUsername('calendar.event.viewer');
        $this->persist($viewer);
        $event = (new SchoolEvent())
            ->setAcademicYear($world['year'])
            ->setDate($this->weekdayInCurrentMonth())
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Claustro')
            ->setGeneral(true);
        $this->persist($event);

        $this->loginAs($viewer, $world['centre']);
        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Claustro', (string) $this->client->getResponse()->getContent());
    }

    public function testUnifiedCalendarHidesRestrictedEventFromUnrelatedTeacher(): void
    {
        $world   = $this->makeScenario();
        $viewer  = (new Teacher(new PersonName('Viewer', 'Teacher')))->setUsername('calendar.event.unrelated');
        $this->persist($viewer);
        $event = (new SchoolEvent())
            ->setAcademicYear($world['year'])
            ->setDate($this->weekdayInCurrentMonth())
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Reunión de 1ºA')
            ->setGeneral(false);
        $event->addGroup($world['group']);
        $this->persist($event);

        $this->loginAs($viewer, $world['centre']);
        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Reunión de 1ºA', (string) $this->client->getResponse()->getContent());
    }

    public function testHidesSaturdayAndSundayColumns(): void
    {
        $centre = $this->makeCentre('46000031');
        $admin  = $this->makeAdmin('calendar.no.weekend');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('>Sáb<', $content);
        self::assertStringNotContainsString('>Dom<', $content);
    }

    public function testDoesNotShowSanctionWithoutDates(): void
    {
        $world   = $this->makeScenario();
        $creator = (new Teacher(new PersonName('Creator', 'Teacher')))->setUsername('calendar.bar.nodate');
        $this->persist($creator);

        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setDetails('Sin fechas asociadas')
            ->setNoMeasureApplied(true);
        $this->persist($sanction);

        $this->loginAs($creator, $world['centre']);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Sin fechas asociadas', (string) $this->client->getResponse()->getContent());
    }

    // ── Pestañas ─────────────────────────────────────────────────────────────

    public function testShowsCalendarAndEventsTabsToAdmin(): void
    {
        $centre = $this->makeCentre('46000060');
        $admin  = $this->makeAdmin('calendar.tabs.admin');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('tab=calendar', $content);
        self::assertStringContainsString('tab=events', $content);
    }

    public function testHidesTabsFromNonAdmin(): void
    {
        $centre  = $this->makeCentre('46000061');
        $teacher = (new Teacher(new PersonName('Plain', 'Teacher')))->setUsername('calendar.tabs.noadmin');
        $this->persist($teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('tab=events', $content);
    }

    public function testNonAdminDoesNotSeeAbsencesInUnifiedCalendar(): void
    {
        $world  = $this->makeScenario();
        $viewer = (new Teacher(new PersonName('Plain', 'Teacher')))->setUsername('calendar.tabs.noadmin.query');
        $absent = (new Teacher(new PersonName('Marta', 'Ruiz')))->setUsername('calendar.tabs.noadmin.absent');
        $this->persist($viewer, $absent);
        $absence = (new Absence())
            ->setTeacher($absent)
            ->setAcademicYear($world['year'])
            ->setStartDate($this->weekdayInCurrentMonth())
            ->setEndDate($this->weekdayInCurrentMonth());
        $this->persist($absence);
        $this->loginAs($viewer, $world['centre']);

        // "tab=absences" ya no es un valor de pestaña real: cualquier valor
        // que no sea "events" cae en la vista de calendario por defecto.
        $this->client->request('GET', '/calendario?tab=absences');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Marta Ruiz', $content);
    }

    // ── Barras de ausencias ──────────────────────────────────────────────────

    public function testUnifiedCalendarShowsAbsenceWithTeacherNameToAdmin(): void
    {
        $world   = $this->makeScenario();
        $teacher = (new Teacher(new PersonName('Marta', 'Ruiz')))->setUsername('calendar.absence.bar');
        $this->persist($teacher);
        $admin = $this->makeAdmin('calendar.absence.bar.admin');

        $absence = (new Absence())
            ->setTeacher($teacher)
            ->setAcademicYear($world['year'])
            ->setStartDate($this->weekdayInCurrentMonth())
            ->setEndDate($this->weekdayInCurrentMonth());
        $this->persist($absence);

        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Marta Ruiz', $content);
    }

    // ── Vista de día ─────────────────────────────────────────────────────────

    public function testDayViewRendersSuccessfully(): void
    {
        $world = $this->makeScenario();
        $admin = $this->makeAdmin('calendar.day.render');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/dia/2026-03-10');

        self::assertResponseIsSuccessful();
    }

    public function testDayViewReturns404ForInvalidDateFormat(): void
    {
        $world = $this->makeScenario();
        $admin = $this->makeAdmin('calendar.day.invalid');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/dia/not-a-date');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDayViewShowsPrevAndNextDayLinks(): void
    {
        $world = $this->makeScenario();
        $admin = $this->makeAdmin('calendar.day.nav');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/dia/2026-03-10');

        self::assertSelectorExists('a[href="/calendario/dia/2026-03-09"]');
        self::assertSelectorExists('a[href="/calendario/dia/2026-03-11"]');
    }

    public function testDayViewShowsAddEventButtonToAdminOnly(): void
    {
        $world   = $this->makeScenario();
        $admin   = $this->makeAdmin('calendar.day.addbtn.admin');
        $teacher = (new Teacher(new PersonName('Plain', 'Teacher')))->setUsername('calendar.day.addbtn.teacher');
        $this->persist($teacher);

        $this->loginAs($admin, $world['centre']);
        $this->client->request('GET', '/calendario/dia/2026-03-10');
        self::assertSelectorExists('a[href="/actividades/nuevo?date=2026-03-10"]');

        $this->loginAs($teacher, $world['centre']);
        $this->client->request('GET', '/calendario/dia/2026-03-10');
        self::assertSelectorNotExists('a[href="/actividades/nuevo?date=2026-03-10"]');
    }

    public function testDayViewHidesAbsencesFromNonAdmin(): void
    {
        $world   = $this->makeScenario();
        $absent  = (new Teacher(new PersonName('Marta', 'Ruiz')))->setUsername('calendar.day.absence.teacher');
        $teacher = (new Teacher(new PersonName('Plain', 'Teacher')))->setUsername('calendar.day.absence.viewer');
        $this->persist($absent, $teacher);
        $absence = (new Absence())
            ->setTeacher($absent)
            ->setAcademicYear($world['year'])
            ->setStartDate(new \DateTimeImmutable('2026-03-10'))
            ->setEndDate(new \DateTimeImmutable('2026-03-10'));
        $this->persist($absence);

        $this->loginAs($teacher, $world['centre']);
        $this->client->request('GET', '/calendario/dia/2026-03-10');
        self::assertStringNotContainsString('Marta Ruiz', (string) $this->client->getResponse()->getContent());

        $admin = $this->makeAdmin('calendar.day.absence.admin');
        $this->loginAs($admin, $world['centre']);
        $this->client->request('GET', '/calendario/dia/2026-03-10');
        self::assertStringContainsString('Marta Ruiz', (string) $this->client->getResponse()->getContent());
    }

    public function testDayViewShowsGeneralEventToAnyTeacher(): void
    {
        $world   = $this->makeScenario();
        $teacher = (new Teacher(new PersonName('Plain', 'Teacher')))->setUsername('calendar.day.event.viewer');
        $this->persist($teacher);
        $event = (new SchoolEvent())
            ->setAcademicYear($world['year'])
            ->setDate(new \DateTimeImmutable('2026-03-10'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Jornada de puertas abiertas')
            ->setGeneral(true);
        $this->persist($event);

        $this->loginAs($teacher, $world['centre']);
        $this->client->request('GET', '/calendario/dia/2026-03-10');

        self::assertStringContainsString('Jornada de puertas abiertas', (string) $this->client->getResponse()->getContent());
    }

    // ── Modo tablón ──────────────────────────────────────────────────────────

    public function testBoardRedirectsToCentreSelectionWithoutSelectedCentre(): void
    {
        $admin = $this->makeAdmin('calendar.board.nocentre');
        $this->makeCentre('46000040');
        $this->makeCentre('46000041');
        $this->loginAs($admin);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseRedirects('/seleccion/centro');
    }

    public function testBoardDeniesAccessToTeacherWithoutAdminRole(): void
    {
        $world   = $this->makeScenario();
        $teacher = (new Teacher(new PersonName('Plain', 'Teacher')))->setUsername('calendar.board.noadmin');
        $this->persist($teacher);
        $this->loginAs($teacher, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseStatusCodeSame(403);
    }

    public function testBoardAllowsAccessToCentreAdminWithoutGlobalAdminRole(): void
    {
        $world   = $this->makeScenario();
        $teacher = (new Teacher(new PersonName('Centre', 'AdminTeacher')))->setUsername('calendar.board.centreadmin');
        $this->persist($teacher);
        $world['centre']->addAdmin($teacher);
        $this->flush();
        $this->loginAs($teacher, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
    }

    public function testBoardRendersWithSelectedCentre(): void
    {
        $centre = $this->makeCentre('46000042');
        $admin  = $this->makeAdmin('calendar.board.ok');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
    }

    public function testBoardShowsAbsencesAndSanctionsTitle(): void
    {
        $centre = $this->makeCentre('46000077');
        $admin  = $this->makeAdmin('calendar.board.title');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tablón de ausencias y sanciones', (string) $this->client->getResponse()->getContent());
    }

    public function testBoardPrependsEducationalCentreNameToTheTitle(): void
    {
        $centre = $this->makeCentre('46000078');
        $admin  = $this->makeAdmin('calendar.board.centrename');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($centre->getName() . ' · Tablón de ausencias y sanciones', $content);
    }

    public function testBoardShowsSanctionGroupedByGroupWithStartAndEndDates(): void
    {
        $world   = $this->makeScenario();
        $creator = (new Teacher(new PersonName('Creator', 'Teacher')))->setUsername('calendar.board.dates')->setAdmin(true);
        $this->persist($creator);

        // Cubre hoy y los días siguientes: se ve en «Hoy» o, si hoy no es lectivo, en «Próximos días».
        $monday = $this->today();
        $friday = $monday->modify('+10 days');

        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setDetails('Expulsión del aula')
            ->setNoMeasureApplied(true)
            ->setEffectiveFrom($monday)
            ->setEffectiveTo($friday);
        $this->persist($sanction);
        $this->notifySanction($sanction, $world['centre'], $creator);

        $this->loginAs($creator, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ana García', $content);
        self::assertStringContainsString('1ºA', $content);
        self::assertStringContainsString('Expulsión del aula', $content);
        self::assertStringContainsString($monday->format('d/m/Y'), $content);
        self::assertStringContainsString($friday->format('d/m/Y'), $content);
    }

    public function testActivatingBoardModeLocksOutTheRestOfTheApplication(): void
    {
        $centre = $this->makeCentre('46000043');
        $admin  = $this->makeAdmin('calendar.board.lock');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/calendario');
        self::assertResponseRedirects('/calendario/tablon');

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/calendario/tablon');
    }

    public function testBoardModeLockStillAllowsLoggingOut(): void
    {
        $centre = $this->makeCentre('46000044');
        $admin  = $this->makeAdmin('calendar.board.logout');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');
        self::assertResponseIsSuccessful();

        $token = static::getContainer()->get('security.csrf.token_manager')->getToken('logout')->getValue();
        $this->client->request('GET', '/logout', ['_csrf_token' => $token]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString(
            '/calendario/tablon',
            (string) $this->client->getResponse()->headers->get('Location')
        );
    }

    public function testBoardShowsTodayAndUpcomingDaysPanelsByDefault(): void
    {
        $centre = $this->makeCentre('46000045');
        $admin  = $this->makeAdmin('calendar.board.defaults');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-seconds="60"', $content);
        self::assertStringContainsString('data-seconds="10"', $content);
        self::assertStringNotContainsString('data-seconds="0"', $content);
        self::assertSame(2, substr_count($content, 'data-board-target="panel"'));
        self::assertMatchesRegularExpression('/>\s*Hoy\s*</', $content);
        self::assertMatchesRegularExpression('/>\s*Próximos días\s*</', $content);
        self::assertStringNotContainsString('Semana que viene', $content);
    }

    public function testBoardOmitsUpcomingDaysWhenItsSecondsIsZero(): void
    {
        $centre = $this->makeCentre('46000047');
        $admin  = $this->makeAdmin('calendar.board.noupcoming');
        $this->seedCentreValue('board.upcoming_seconds', '0', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, substr_count($content, 'data-board-target="panel"'));
        self::assertStringNotContainsString('Próximos días', $content);
        self::assertMatchesRegularExpression('/>\s*Hoy\s*</', $content);
    }

    public function testBoardShowsOnlyTodayWhenAllScreensAreZero(): void
    {
        $centre = $this->makeCentre('46000048');
        $admin  = $this->makeAdmin('calendar.board.allzero');
        $this->seedCentreValue('board.today_seconds', '0', $centre);
        $this->seedCentreValue('board.upcoming_seconds', '0', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, substr_count($content, 'data-board-target="panel"'));
        self::assertStringContainsString('data-seconds="0"', $content);
        self::assertMatchesRegularExpression('/>\s*Hoy\s*</', $content);
        self::assertStringNotContainsString('Próximos días', $content);
    }

    public function testBoardHidesPrevNextButtonsWhenThereIsOnlyOneScreen(): void
    {
        $centre = $this->makeCentre('46000072');
        $admin  = $this->makeAdmin('calendar.board.onescreen');
        $this->seedCentreValue('board.today_seconds', '0', $centre);
        $this->seedCentreValue('board.upcoming_seconds', '0', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('data-action="board#prev"', $content);
        self::assertStringNotContainsString('data-action="board#next"', $content);
    }

    public function testBoardShowsPrevNextButtonsWhenThereAreMultipleScreens(): void
    {
        $centre = $this->makeCentre('46000073');
        $admin  = $this->makeAdmin('calendar.board.multiscreen');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-action="board#prev"', $content);
        self::assertStringContainsString('data-action="board#next"', $content);
    }

    public function testBoardReflectsCustomDurationSettings(): void
    {
        $centre = $this->makeCentre('46000071');
        $admin  = $this->makeAdmin('calendar.board.custom');
        $this->seedCentreValue('board.today_seconds', '45', $centre);
        $this->seedCentreValue('board.upcoming_seconds', '30', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-seconds="45"', $content);
        self::assertStringContainsString('data-seconds="30"', $content);
    }

    public function testBoardUsesLightThemeByDefault(): void
    {
        $centre = $this->makeCentre('46000049');
        $admin  = $this->makeAdmin('calendar.board.themedefault');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-board-theme-mode-value="light"', $content);
    }

    public function testBoardReflectsCustomThemeSetting(): void
    {
        $centre = $this->makeCentre('46000050');
        $admin  = $this->makeAdmin('calendar.board.themecustom');
        $this->seedCentreValue('board.theme', 'dark', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-board-theme-mode-value="dark"', $content);
    }

    // ── Pantalla "Hoy" ───────────────────────────────────────────────────────

    public function testBoardTodayShowsFormattedDateHeader(): void
    {
        $centre = $this->makeCentre('46000072');
        $admin  = $this->makeAdmin('calendar.board.today.date');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $today   = $this->today();
        self::assertStringContainsString($this->weekdayLabel($today) . ', ' . $today->format('d/m/Y'), $content);
    }

    public function testBoardTodayShowsGuardDutyForTodaysTimeSlots(): void
    {
        $world = $this->makeScenario();
        $guard = (new Teacher(new PersonName('Luis', 'Navas')))->setUsername('calendar.board.today.guard');
        $this->persist($guard);

        $timeSlot = $this->makeTimeSlot($world['year'], $this->todayDayOfWeek());
        $timeSlot->addGuard($guard);
        $this->flush();

        $admin = $this->makeAdmin('calendar.board.today.guard.admin');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Luis Navas', $content);
        self::assertStringContainsString($timeSlot->getName(), $content);
    }

    public function testBoardTodayShowsActivityWithSubjectGroupDialogAndAttachment(): void
    {
        $world  = $this->makeScenario();
        $absent = (new Teacher(new PersonName('Marta', 'Ruiz')))->setUsername('calendar.board.today.absent');
        $this->persist($absent);

        $groupTeacher = new GroupTeacher($world['group'], $absent, 'Programación');
        $this->persist($groupTeacher);

        $timeSlot = $this->makeTimeSlot($world['year'], $this->todayDayOfWeek());
        $today    = $this->today();

        $absence = (new Absence())
            ->setTeacher($absent)
            ->setAcademicYear($world['year'])
            ->setStartDate($today)
            ->setEndDate($today);
        $this->persist($absence);

        $activity = (new Activity())
            ->setAbsence($absence)
            ->setDate($today)
            ->setTimeSlot($timeSlot)
            ->setDescription('<p>Repaso de unidad 3.</p>');
        $activity->addSubject($groupTeacher);
        $this->persist($activity);

        $attachment = new ActivityAttachment($activity, 'ejercicios.pdf', 'application/pdf', 4, 'test');
        $activity->addAttachment($attachment);
        $this->persist($activity, $attachment);
        $this->flush();

        $admin = $this->makeAdmin('calendar.board.today.activity.admin');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Marta Ruiz', $content);
        self::assertStringContainsString('Programación', $content);
        self::assertStringContainsString($world['group']->getName(), $content);
        self::assertStringContainsString('Repaso de unidad 3.', $content);
        self::assertStringContainsString('data-controller="dialog"', $content);
        self::assertStringContainsString('ejercicios.pdf', $content);
        self::assertStringContainsString(
            '/ausencias/' . $absence->getId()->toRfc4122() . '/actividades/' . $activity->getId()->toRfc4122() . '/adjuntos/' . $attachment->getId()->toRfc4122(),
            $content
        );
    }

    public function testBoardTodayListsAbsentTeachersSortedByLastNameAtTheBottom(): void
    {
        $world = $this->makeScenario();
        $bravo = (new Teacher(new PersonName('Ana', 'Bravo')))->setUsername('calendar.board.today.bravo');
        $ruiz  = (new Teacher(new PersonName('Marta', 'Ruiz')))->setUsername('calendar.board.today.ruiz');
        $this->persist($bravo, $ruiz);

        $today = $this->today();
        foreach ([$ruiz, $bravo] as $teacher) {
            $absence = (new Absence())
                ->setTeacher($teacher)
                ->setAcademicYear($world['year'])
                ->setStartDate($today)
                ->setEndDate($today);
            $this->persist($absence);
        }
        $this->flush();

        $admin = $this->makeAdmin('calendar.board.today.sorted.admin');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        // Sin tramos horarios configurados se lista a quién falta, por apellido.
        self::assertStringContainsString('Ana Bravo', $content);
        self::assertStringContainsString('Marta Ruiz', $content);
        self::assertLessThan(strpos($content, 'Marta Ruiz'), strpos($content, 'Ana Bravo'));
    }

    public function testBoardTodayShowsEmptyStateWhenNoAbsences(): void
    {
        $centre = $this->makeCentre('46000073');
        $admin  = $this->makeAdmin('calendar.board.today.noabsences');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ningún docente ausente', $content);
    }

    public function testBoardTodayPutsATeacherWithoutTasksInEveryTimeSlotAndOneWithTasksOnlyInHis(): void
    {
        $world   = $this->makeScenario();
        $free    = (new Teacher(new PersonName('Ana', 'Bravo')))->setUsername('calendar.board.today.free');
        $tasked  = (new Teacher(new PersonName('Marta', 'Ruiz')))->setUsername('calendar.board.today.tasked');
        $this->persist($free, $tasked);

        $dayOfWeek = $this->todayDayOfWeek();
        $first     = $this->makeTimeSlot($world['year'], $dayOfWeek);
        $second    = (new TimeSlot())->setName('Tramo 2')->setDayOfWeek($dayOfWeek)
            ->setStartTime(new \DateTimeImmutable('09:00'))->setEndTime(new \DateTimeImmutable('10:00'))->setAcademicYear($world['year']);
        $this->persist($second);

        $today = $this->today();
        foreach ([$free, $tasked] as $teacher) {
            $this->persist((new Absence())->setTeacher($teacher)->setAcademicYear($world['year'])->setStartDate($today)->setEndDate($today));
        }
        $taskedAbsence = $this->em->getRepository(Absence::class)->findOneBy(['teacher' => $tasked]);
        $activity      = (new Activity())->setAbsence($taskedAbsence)->setDate($today)->setTimeSlot($second)
            ->setDescription('<p>Repaso.</p>')->setClassroom('Aula 7');
        $this->persist($activity);
        $this->flush();

        $admin = $this->makeAdmin('calendar.board.today.everyslot.admin');
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $slots = $crawler->filter('[data-current-time-slot-target="slot"]');
        self::assertCount(2, $slots, 'los dos tramos tienen a alguien ausente');
        self::assertStringContainsString('Ana Bravo', $slots->eq(0)->text(), 'sin tareas: en todos los tramos');
        self::assertStringContainsString('Ana Bravo', $slots->eq(1)->text());
        self::assertStringNotContainsString('Marta Ruiz', $slots->eq(0)->text(), 'con tareas: solo en su tramo');
        self::assertStringContainsString('Marta Ruiz', $slots->eq(1)->text());
        self::assertStringContainsString('Aula: Aula 7', $slots->eq(1)->text());
        self::assertSame($first->getName(), trim($slots->eq(0)->filter('span.text-lg')->text()));
    }

    public function testBoardTodayMarksTimeSlotCardsWithStartAndEndForCurrentTimeHighlighting(): void
    {
        $world    = $this->makeScenario();
        $timeSlot = $this->makeTimeSlot($world['year'], $this->todayDayOfWeek());
        $guard    = (new Teacher(new PersonName('Luis', 'Navas')))->setUsername('calendar.board.today.highlight.guard');
        $this->persist($guard);
        $timeSlot->addGuard($guard);
        $this->flush();

        $admin = $this->makeAdmin('calendar.board.today.currentslot.admin');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-controller="current-time-slot"', $content);
        self::assertStringContainsString('data-current-time-slot-target="slot"', $content);
        self::assertStringContainsString('data-start="' . $timeSlot->getStartTime()->format('H:i') . '"', $content);
        self::assertStringContainsString('data-end="' . $timeSlot->getEndTime()->format('H:i') . '"', $content);
    }

    public function testBoardTodayShowsSanctionedStudentsWithGroupAndCalendarLabel(): void
    {
        $world   = $this->makeScenario();
        $creator = (new Teacher(new PersonName('Creator', 'Teacher')))->setUsername('calendar.board.today.sanction.label');
        $this->persist($creator);

        $today    = $this->today();
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setDetails('Detalle largo que no debería mostrarse')
            ->setCalendarLabel('Sin recreo')
            ->setNoMeasureApplied(true)
            ->setEffectiveFrom($today);
        $this->persist($sanction);
        $this->notifySanction($sanction, $world['centre'], $creator);

        $admin = $this->makeAdmin('calendar.board.today.sanction.label.admin');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Alumnado sancionado', $content);
        self::assertStringContainsString('Vigentes hoy', $content);
        self::assertStringContainsString('Ana García', $content);
        self::assertStringContainsString('1ºA', $content);
        self::assertStringContainsString('Sin recreo', $content);
        self::assertStringNotContainsString('Detalle largo', $content);
    }

    public function testBoardTodayFallsBackToTruncatedDetailsWhenNoCalendarLabel(): void
    {
        $world   = $this->makeScenario();
        $creator = (new Teacher(new PersonName('Creator', 'Teacher')))->setUsername('calendar.board.today.sanction.details');
        $this->persist($creator);

        $today    = $this->today();
        $longText = str_repeat('Comportamiento disruptivo en clase de matemáticas. ', 3);
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setDetails('<p>' . $longText . '</p>')
            ->setNoMeasureApplied(true)
            ->setEffectiveFrom($today);
        $this->persist($sanction);
        $this->notifySanction($sanction, $world['centre'], $creator);

        $admin = $this->makeAdmin('calendar.board.today.sanction.details.admin');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('…', $content);
        self::assertStringNotContainsString($longText, $content);
        self::assertStringNotContainsString('<p>', $content);
    }

    public function testBoardTodayShowsEmptyStateWhenNoSanctions(): void
    {
        $centre = $this->makeCentre('46000074');
        $admin  = $this->makeAdmin('calendar.board.today.nosanctions');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ningún alumno sancionado', $content);
    }

    public function testBoardTodayShowsLiveClockAfterDate(): void
    {
        $centre = $this->makeCentre('46000075');
        $admin  = $this->makeAdmin('calendar.board.today.clock');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-controller="clock"', $content);
        self::assertStringContainsString('data-clock-target="display"', $content);
    }

    public function testBoardTodayShowsNonWorkingDayMessageInsteadOfTimeSlots(): void
    {
        $world = $this->makeScenario();
        $this->makeTimeSlot($world['year'], $this->todayDayOfWeek());

        $nonWorkingDay = (new NonWorkingDay())
            ->setAcademicYear($world['year'])
            ->setDate($this->today())
            ->setDescription('Día del Centro');
        $this->persist($nonWorkingDay);

        $admin = $this->makeAdmin('calendar.board.today.nonworking');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Día no lectivo: Día del Centro', $content);
        self::assertStringNotContainsString('data-current-time-slot-target="slot"', $content);
    }

    public function testBoardUpcomingDaysShowsFiveLectiveDaysAfterTodaySkippingNonWorkingOnes(): void
    {
        $world = $this->makeScenario();
        $today = $this->today();

        // El primer día lectivo posterior a hoy pasa a ser festivo: la pantalla empieza en el siguiente.
        $first = $today->modify('+1 day');
        while ((int) $first->format('N') >= 6) {
            $first = $first->modify('+1 day');
        }
        $this->persist((new NonWorkingDay())->setAcademicYear($world['year'])->setDate($first)->setDescription('Puente'));

        $admin = $this->makeAdmin('calendar.board.upcoming.days');
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $headers = $crawler->filter('section[data-seconds="10"] h3')->each(static fn ($node): string => trim($node->text()));
        self::assertCount(5, $headers, 'cinco jornadas lectivas');
        self::assertStringNotContainsString(
            $first->format('j') . ' de',
            $headers[0],
            'el festivo no abre la pantalla',
        );
    }

    public function testBoardTodayListsTheActivitiesOfTodayAndTheNextDaysWithTheirTypeAndData(): void
    {
        $world = $this->makeScenario();
        $today = $this->today();
        $type  = (new SchoolEventType())->setEducationalCentre($world['centre'])->setName('Extraescolar')->setColor('#2563eb');
        $this->persist($type);
        $coordinator = (new Teacher(new PersonName('Mayte', 'Serna')))->setUsername('calendar.board.activities.coord');
        $this->persist($coordinator);

        foreach ([[0, 'Charla de hoy'], [10, 'Visita dentro de plazo'], [40, 'Excursión lejana']] as [$offset, $name]) {
            $this->persist((new SchoolEvent())
                ->setAcademicYear($world['year'])
                ->setDate($today->modify("+{$offset} days"))
                ->setStartTime(new \DateTimeImmutable('11:30'))
                ->setEndTime(new \DateTimeImmutable('13:30'))
                ->setName($name)
                ->setGeneral(true)
                ->setType($type)
                ->setLocation('El Pósito')
                ->setOrganizingDepartment('Geografía')
                ->setCoordinator($coordinator));
        }

        $admin = $this->makeAdmin('calendar.board.activities.admin');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Charla de hoy', $content);
        self::assertStringContainsString('Visita dentro de plazo', $content);
        self::assertStringNotContainsString('Excursión lejana', $content, 'fuera de los 15 días de antelación');
        self::assertStringContainsString('Extraescolar', $content);
        self::assertStringContainsString('El Pósito', $content);
        self::assertStringContainsString('Coordina: Mayte Serna', $content);
        self::assertStringContainsString('Organiza: Geografía', $content);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function today(): \DateTimeImmutable
    {
        return Clock::get()->now()->setTime(0, 0, 0);
    }

    private function todayDayOfWeek(): int
    {
        return ((int) $this->today()->format('N')) - 1;
    }

    private function weekdayLabel(\DateTimeImmutable $date): string
    {
        return match ((int) $date->format('N')) {
            1       => 'Lunes',
            2       => 'Martes',
            3       => 'Miércoles',
            4       => 'Jueves',
            5       => 'Viernes',
            6       => 'Sábado',
            default => 'Domingo',
        };
    }

    private function makeTimeSlot(AcademicYear $year, int $dayOfWeek): TimeSlot
    {
        $timeSlot = (new TimeSlot())
            ->setName('Tramo 1')
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new \DateTimeImmutable('08:00'))
            ->setEndTime(new \DateTimeImmutable('09:00'))
            ->setAcademicYear($year);
        $this->persist($timeSlot);

        return $timeSlot;
    }

    private function seedCentreValue(string $key, string $value, EducationalCentre $centre): CentreSettingValue
    {
        $def    = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => $key]);
        $entity = (new CentreSettingValue())->setDefinition($def)->setCentre($centre)->setValue($value);
        $this->persist($entity);

        return $entity;
    }

    /**
     * Returns a weekday (Mon–Fri) date within the mocked "today" month, so the
     * default calendar view (which opens on today's month) always shows it regardless
     * of which day of the week the test suite happens to run on.
     */
    private function weekdayInCurrentMonth(): \DateTimeImmutable
    {
        $today = Clock::get()->now();
        $date  = $today->setDate((int) $today->format('Y'), (int) $today->format('n'), 15)->setTime(0, 0, 0);

        return match ((int) $date->format('N')) {
            6       => $date->modify('+2 days'),
            7       => $date->modify('+1 day'),
            default => $date,
        };
    }

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student}
     */
    private function makeScenario(): array
    {
        $centre    = (new EducationalCentre())->setCode('46000030')->setName('IES Calendar')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA')->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-CAL-' . uniqid('', false));
        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student);

        return compact('centre', 'year', 'group', 'student');
    }

    private function makeCentre(string $code): EducationalCentre
    {
        $centre = (new EducationalCentre())->setCode($code)->setName('IES ' . $code)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();

        return $centre;
    }

    private function notifySanction(Sanction $sanction, EducationalCentre $centre, Teacher $teacher): void
    {
        $method = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($method);

        $communication = Communication::forSanction($sanction, $method, $teacher, new \DateTimeImmutable(), CommunicationResult::Notified);
        $this->persist($communication);
        $sanction->setNotifiedCommunication($communication);
        $this->flush();
    }

    private function makeAdmin(string $username): Teacher
    {
        $admin = (new Teacher(new PersonName('Cal', 'Endar')))->setUsername($username)->setAdmin(true);
        $this->persist($admin);

        return $admin;
    }
}
