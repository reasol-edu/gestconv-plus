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
use App\Entity\SettingDefinition;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Tests\Integration\ControllerTestCase;

class CalendarControllerTest extends ControllerTestCase
{
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

    public function testShowsBothTabsToAdmin(): void
    {
        $centre = $this->makeCentre('46000060');
        $admin  = $this->makeAdmin('calendar.tabs.admin');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Calendario de sanciones', $content);
        self::assertStringContainsString('Calendario de ausencias', $content);
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
        self::assertStringNotContainsString('Calendario de ausencias', $content);
    }

    public function testNonAdminCannotSwitchToAbsencesTabViaQueryParam(): void
    {
        $world   = $this->makeScenario();
        $viewer  = (new Teacher(new PersonName('Plain', 'Teacher')))->setUsername('calendar.tabs.noadmin.query');
        $absent  = (new Teacher(new PersonName('Marta', 'Ruiz')))->setUsername('calendar.tabs.noadmin.absent');
        $this->persist($viewer, $absent);
        $absence = (new Absence())
            ->setTeacher($absent)
            ->setAcademicYear($world['year'])
            ->setStartDate($this->weekdayInCurrentMonth())
            ->setEndDate($this->weekdayInCurrentMonth());
        $this->persist($absence);
        $this->loginAs($viewer, $world['centre']);

        $this->client->request('GET', '/calendario?tab=absences');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Marta Ruiz', $content);
    }

    // ── Barras de ausencias ──────────────────────────────────────────────────

    public function testAbsencesTabShowsOnlyTeacherNameOnTheBar(): void
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

        $this->client->request('GET', '/calendario?tab=absences');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Marta Ruiz', $content);
    }

    public function testAbsencesTabShowsBoardModeLinkToAdmin(): void
    {
        $centre = $this->makeCentre('46000062');
        $admin  = $this->makeAdmin('calendar.absence.tablon.admin');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario?tab=absences');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/calendario/tablon', (string) $this->client->getResponse()->getContent());
    }

    public function testSanctionsTabIsShownByDefaultAndDoesNotIncludeAbsences(): void
    {
        $world   = $this->makeScenario();
        $teacher = (new Teacher(new PersonName('Marta', 'Ruiz')))->setUsername('calendar.absence.default');
        $this->persist($teacher);
        $admin = $this->makeAdmin('calendar.absence.default.admin');

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
        self::assertStringNotContainsString('Marta Ruiz', $content);
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

        $monday = (new \DateTimeImmutable('today'))->modify('monday this week');
        $friday = $monday->modify('+2 days');

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

    public function testBoardShowsTodayAndCurrentWeekPanelsByDefaultButOmitsNextWeek(): void
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
        self::assertMatchesRegularExpression('/>\s*Hoy\s*</', $content);
        self::assertStringContainsString('Esta semana', $content);
        self::assertStringNotContainsString('Semana que viene', $content);
    }

    public function testBoardShowsNextWeekWhenItsSecondsIsSetAboveZero(): void
    {
        $centre = $this->makeCentre('46000046');
        $admin  = $this->makeAdmin('calendar.board.withnext');
        $this->seedCentreValue('board.next_week_seconds', '20', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-seconds="20"', $content);
        self::assertStringContainsString('Semana que viene', $content);
    }

    public function testBoardOmitsCurrentWeekWhenItsSecondsIsZero(): void
    {
        $centre = $this->makeCentre('46000047');
        $admin  = $this->makeAdmin('calendar.board.nocurrent');
        $this->seedCentreValue('board.current_week_seconds', '0', $centre);
        $this->seedCentreValue('board.next_week_seconds', '20', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Esta semana', $content);
        self::assertStringContainsString('Semana que viene', $content);
    }

    public function testBoardShowsOnlyTodayWhenAllScreensAreZero(): void
    {
        $centre = $this->makeCentre('46000048');
        $admin  = $this->makeAdmin('calendar.board.allzero');
        $this->seedCentreValue('board.today_seconds', '0', $centre);
        $this->seedCentreValue('board.current_week_seconds', '0', $centre);
        $this->seedCentreValue('board.next_week_seconds', '0', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, substr_count($content, 'data-board-target="panel"'));
        self::assertStringContainsString('data-seconds="0"', $content);
        self::assertMatchesRegularExpression('/>\s*Hoy\s*</', $content);
        self::assertStringNotContainsString('Esta semana', $content);
        self::assertStringNotContainsString('Semana que viene', $content);
    }

    public function testBoardHidesPrevNextButtonsWhenThereIsOnlyOneScreen(): void
    {
        $centre = $this->makeCentre('46000072');
        $admin  = $this->makeAdmin('calendar.board.onescreen');
        $this->seedCentreValue('board.today_seconds', '0', $centre);
        $this->seedCentreValue('board.current_week_seconds', '0', $centre);
        $this->seedCentreValue('board.next_week_seconds', '0', $centre);
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
        $this->seedCentreValue('board.current_week_seconds', '30', $centre);
        $this->seedCentreValue('board.next_week_seconds', '10', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-seconds="45"', $content);
        self::assertStringContainsString('data-seconds="30"', $content);
        self::assertStringContainsString('data-seconds="10"', $content);
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
        $today   = new \DateTimeImmutable('today');
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
        $today    = new \DateTimeImmutable('today');

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

        $today = new \DateTimeImmutable('today');
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
        self::assertStringContainsString('Ana Bravo, Marta Ruiz', $content);
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

    public function testBoardTodayLabelsTimeSlotActivityListWithAusencias(): void
    {
        $world  = $this->makeScenario();
        $absent = (new Teacher(new PersonName('Marta', 'Ruiz')))->setUsername('calendar.board.today.slotlabel');
        $this->persist($absent);

        $timeSlot = $this->makeTimeSlot($world['year'], $this->todayDayOfWeek());
        $today    = new \DateTimeImmutable('today');

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
            ->setDescription('<p>Repaso.</p>');
        $this->persist($activity);
        $this->flush();

        $admin = $this->makeAdmin('calendar.board.today.slotlabel.admin');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ausencias con actividades:', $content);
    }

    public function testBoardTodayMarksTimeSlotCardsWithStartAndEndForCurrentTimeHighlighting(): void
    {
        $world    = $this->makeScenario();
        $timeSlot = $this->makeTimeSlot($world['year'], $this->todayDayOfWeek());

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

        $today    = new \DateTimeImmutable('today');
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
        self::assertStringContainsString('Alumnado sancionado hoy', $content);
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

        $today    = new \DateTimeImmutable('today');
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
            ->setDate(new \DateTimeImmutable('today'))
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

    public function testBoardWeekMarksNonWorkingDayColumnWithItsLabel(): void
    {
        $world  = $this->makeScenario();
        $monday = (new \DateTimeImmutable('today'))->modify('monday this week');

        $nonWorkingDay = (new NonWorkingDay())
            ->setAcademicYear($world['year'])
            ->setDate($monday)
            ->setDescription('Puente');
        $this->persist($nonWorkingDay);

        $admin = $this->makeAdmin('calendar.board.week.nonworking');
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Puente', $content);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function todayDayOfWeek(): int
    {
        return ((int) (new \DateTimeImmutable('today'))->format('N')) - 1;
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
     * Returns a weekday (Mon–Fri) date within the current real-world month, so the
     * default calendar view (which opens on today's month) always shows it regardless
     * of which day of the week the test suite happens to run on.
     */
    private function weekdayInCurrentMonth(): \DateTimeImmutable
    {
        $today = new \DateTimeImmutable();
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
