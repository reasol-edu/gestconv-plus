<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Sanction;
use App\Entity\SettingDefinition;
use App\Entity\Student;
use App\Entity\Teacher;
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

    public function testBoardShowsNextWeekTargetWithDefaultDurations(): void
    {
        $centre = $this->makeCentre('46000045');
        $admin  = $this->makeAdmin('calendar.board.defaults');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-board-current-seconds-value="15"', $content);
        self::assertStringContainsString('data-board-next-seconds-value="5"', $content);
        self::assertStringContainsString('data-board-target="currentWeek"', $content);
        self::assertStringContainsString('data-board-target="nextWeek"', $content);
    }

    public function testBoardHidesNextWeekWhenNextWeekSecondsIsZero(): void
    {
        $centre = $this->makeCentre('46000046');
        $admin  = $this->makeAdmin('calendar.board.nonext');
        $this->seedCentreValue('board.next_week_seconds', '0', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-board-target="currentWeek"', $content);
        self::assertStringNotContainsString('data-board-target="nextWeek"', $content);
    }

    public function testBoardHidesNextWeekWhenCurrentWeekSecondsIsZero(): void
    {
        $centre = $this->makeCentre('46000047');
        $admin  = $this->makeAdmin('calendar.board.nocurrent');
        $this->seedCentreValue('board.current_week_seconds', '0', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('data-board-target="nextWeek"', $content);
    }

    public function testBoardReflectsCustomDurationSettings(): void
    {
        $centre = $this->makeCentre('46000048');
        $admin  = $this->makeAdmin('calendar.board.custom');
        $this->seedCentreValue('board.current_week_seconds', '30', $centre);
        $this->seedCentreValue('board.next_week_seconds', '10', $centre);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/calendario/tablon');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-board-current-seconds-value="30"', $content);
        self::assertStringContainsString('data-board-next-seconds-value="10"', $content);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

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
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA')->setProgrammeYear($level);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-CAL-' . uniqid('', false));
        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $programme, $level, $group, $student);

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

    private function makeAdmin(string $username): Teacher
    {
        $admin = (new Teacher(new PersonName('Cal', 'Endar')))->setUsername($username)->setAdmin(true);
        $this->persist($admin);

        return $admin;
    }
}
