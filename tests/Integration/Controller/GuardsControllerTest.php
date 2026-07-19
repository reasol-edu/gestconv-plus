<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Activity;
use App\Entity\ActivityAttachment;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\SanctionTaskAttachment;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class GuardsControllerTest extends ControllerTestCase
{
    // ── acceso ───────────────────────────────────────────────────────────────

    public function testTeacherWithoutGuardDutyAndNotAdminIsForbidden(): void
    {
        $world = $this->makeWorld('noaccess');
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', '/guardias');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTeacherWithGuardDutySomewhereInTheYearCanAccessRegardlessOfViewedDay(): void
    {
        $world    = $this->makeWorld('anyslot');
        $todayDow = self::todayDayOfWeek();
        $otherDow = ($todayDow + 3) % 7;
        $slot     = $this->makeTimeSlot($world['year'], 'Guardia', $otherDow, '10:00', '10:55');
        $slot->addGuard($world['teacher']);
        $this->persist($slot);
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
    }

    public function testAdminCanAccessWithoutGuardDuty(): void
    {
        $world = $this->makeWorld('admin');
        $admin = $this->makeTeacher('admin-noguard');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
    }

    // ── filtrado de tramos ──────────────────────────────────────────────────

    public function testGuardTeacherSeesOnlyOwnSlotsForTheDay(): void
    {
        $world    = $this->makeWorld('ownslots');
        $todayDow = self::todayDayOfWeek();

        $ownSlot   = $this->makeTimeSlot($world['year'], 'Tramo propio', $todayDow, '08:00', '08:55');
        $otherSlot = $this->makeTimeSlot($world['year'], 'Tramo ajeno', $todayDow, '09:00', '09:55');
        $ownSlot->addGuard($world['teacher']);
        $this->persist($ownSlot, $otherSlot);

        $this->loginAs($world['teacher'], $world['centre']);
        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Tramo propio', $html);
        self::assertStringNotContainsString('Tramo ajeno', $html);
    }

    public function testAdminSeesAllSlotsForTheDay(): void
    {
        $world    = $this->makeWorld('allslots');
        $todayDow = self::todayDayOfWeek();

        $slotA = $this->makeTimeSlot($world['year'], 'Tramo A', $todayDow, '08:00', '08:55');
        $slotB = $this->makeTimeSlot($world['year'], 'Tramo B', $todayDow, '09:00', '09:55');
        $slotA->addGuard($world['teacher']);
        $this->persist($slotA, $slotB);

        $admin = $this->makeTeacher('admin-allslots');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Tramo A', $html);
        self::assertStringContainsString('Tramo B', $html);
    }

    // ── navegación por día ──────────────────────────────────────────────────

    public function testTodayPrefixShownOnlyWhenViewingToday(): void
    {
        $world = $this->makeWorld('todayprefix');
        $admin = $this->makeTeacher('admin-todayprefix');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('guards.today_prefix', 'admin'), $crawler->html());

        $otherDate = (new \DateTimeImmutable('today'))->modify('+5 days')->format('Y-m-d');
        $crawler   = $this->client->request('GET', '/guardias?date=' . $otherDate);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($this->trans('guards.today_prefix', 'admin'), $crawler->html());
    }

    public function testDateNavigationLinksPointToAdjacentDays(): void
    {
        $world = $this->makeWorld('navlinks');
        $admin = $this->makeTeacher('admin-navlinks');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $date = (new \DateTimeImmutable('today'))->modify('+5 days');
        $crawler = $this->client->request('GET', '/guardias?date=' . $date->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('date=' . $date->modify('-1 day')->format('Y-m-d'), $html);
        self::assertStringContainsString('date=' . $date->modify('+1 day')->format('Y-m-d'), $html);
    }

    public function testCurrentTimeSlotWiringOnlyPresentWhenViewingToday(): void
    {
        $world     = $this->makeWorld('wiring');
        $todayDow  = self::todayDayOfWeek();
        $otherDate = (new \DateTimeImmutable('today'))->modify('+5 days');
        $this->persist(
            $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55'),
            // Necesitamos un tramo ese otro día para poder comprobar la ausencia del cableado.
            $this->makeTimeSlot($world['year'], 'Tramo otro día', (int) $otherDate->format('N') - 1, '08:00', '08:55'),
        );

        $admin = $this->makeTeacher('admin-wiring');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-controller="current-time-slot"', $crawler->html());

        $crawler = $this->client->request('GET', '/guardias?date=' . $otherDate->format('Y-m-d'));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('data-controller="current-time-slot"', $crawler->html());
    }

    // ── profesorado de guardia y edición de tramos (admin) ───────────────────

    public function testOnDutyTeachersListedInSlot(): void
    {
        $world    = $this->makeWorld('onduty');
        $todayDow = self::todayDayOfWeek();
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $guard    = $this->makeTeacherNamed('onduty-guard', 'Marta', 'Ruiz');
        $slot->addGuard($guard);
        $slot->addGuard($world['teacher']);
        $this->persist($slot);

        $this->loginAs($world['teacher'], $world['centre']);
        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Marta Ruiz', $crawler->html());
    }

    public function testOnDutyEmptyMessageShownWhenSlotHasNoGuards(): void
    {
        $world    = $this->makeWorld('ondutyempty');
        $admin    = $this->makeTeacher('admin-ondutyempty');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $todayDow = self::todayDayOfWeek();
        $this->persist($this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55'));

        $this->loginAs($admin, $world['centre']);
        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('guards.on_duty_empty', 'admin'), $crawler->html());
    }

    public function testEditTimeSlotButtonHiddenForNonAdminGuard(): void
    {
        $world    = $this->makeWorld('editbtnteacher');
        $todayDow = self::todayDayOfWeek();
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $slot->addGuard($world['teacher']);
        $this->persist($slot);

        $editUrl = '/centro/' . $world['centre']->getId()->toRfc4122() . '/tramos-horarios?slot=' . $slot->getId()->toRfc4122();

        $this->loginAs($world['teacher'], $world['centre']);
        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($editUrl, $crawler->html());
    }

    public function testEditTimeSlotButtonShownForAdmin(): void
    {
        $world    = $this->makeWorld('editbtnadmin');
        $todayDow = self::todayDayOfWeek();
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $this->persist($slot);

        $editUrl = '/centro/' . $world['centre']->getId()->toRfc4122() . '/tramos-horarios?slot=' . $slot->getId()->toRfc4122();

        $admin = $this->makeTeacher('admin-editbtnadmin');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);
        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($editUrl, $crawler->html());
    }

    public function testEditTimeSlotButtonPreselectsSlotInAdminScreen(): void
    {
        $world    = $this->makeWorld('editpreselect');
        $todayDow = self::todayDayOfWeek();
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo preseleccionado', $todayDow, '08:00', '08:55');
        $this->persist($slot);

        $admin = $this->makeTeacher('admin-editpreselect');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request(
            'GET',
            '/centro/' . $world['centre']->getId()->toRfc4122() . '/tramos-horarios?slot=' . $slot->getId()->toRfc4122(),
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="Tramo preseleccionado"]');
    }

    // ── ausencias y actividades ──────────────────────────────────────────────

    public function testActivityInSlotShowsDescriptionAndAttachmentLink(): void
    {
        $world    = $this->makeWorld('activity');
        $todayDow = self::todayDayOfWeek();
        $today    = new \DateTimeImmutable('today');
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $this->persist($slot);

        $absentTeacher = $this->makeTeacher('absent-activity');
        $absence       = $this->makeAbsence($world['year'], $absentTeacher, $today);
        $activity      = $this->makeActivity($absence, $slot, $today, '<p>Ejercicios de repaso.</p>');
        $attachment    = new ActivityAttachment($activity, 'hoja.txt', 'text/plain', 4, 'test');
        $activity->addAttachment($attachment);
        $this->persist($absence, $activity, $attachment);

        $admin = $this->makeTeacher('admin-activity');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Ejercicios de repaso.', $html);
        self::assertStringContainsString('hoja.txt', $html);
    }

    public function testAbsenceWithoutActivityInSlotShowsPlaceholder(): void
    {
        $world    = $this->makeWorld('noactivity');
        $todayDow = self::todayDayOfWeek();
        $today    = new \DateTimeImmutable('today');
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $this->persist($slot);

        $absentTeacher = $this->makeTeacher('absent-noactivity');
        $this->persist($this->makeAbsence($world['year'], $absentTeacher, $today));

        $admin = $this->makeTeacher('admin-noactivity');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('guards.no_activity', 'admin'), $crawler->html());
    }

    public function testNoAbsencesShowsEmptyMessage(): void
    {
        $world    = $this->makeWorld('noabsences');
        $todayDow = self::todayDayOfWeek();
        $this->persist($this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55'));

        $admin = $this->makeTeacher('admin-noabsences');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->trans('board_today_no_absences', 'calendar'), $crawler->html());
    }

    // ── alumnado sancionado ──────────────────────────────────────────────────

    public function testSanctionedStudentsGroupedByGroupShowTasksOnceWhenExpanded(): void
    {
        $world    = $this->makeWorld('sanctioned');
        $todayDow = self::todayDayOfWeek();
        // Dos tramos el mismo día, para comprobar que la sección de sancionados no se repite.
        $this->persist(
            $this->makeTimeSlot($world['year'], 'Tramo 1', $todayDow, '08:00', '08:55'),
            $this->makeTimeSlot($world['year'], 'Tramo 2', $todayDow, '09:00', '09:55'),
        );

        $today    = new \DateTimeImmutable('today');
        $sanction = $this->makeNotifiedSanction($world, $today->modify('-1 day'), $today->modify('+1 day'));
        $task     = new SanctionTask($sanction, $world['groupTeacher']);
        $task->setDescription('<p>Ejercicios de la materia.</p>')->setCompletedAt($today);
        $attachment = new SanctionTaskAttachment($task, 'tarea.txt', 'text/plain', 4, 'test');
        $task->addAttachment($attachment);
        $this->persist($task, $attachment);

        $admin = $this->makeTeacher('admin-sanctioned');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString($world['group']->getName(), $html);
        self::assertStringContainsString($world['student']->getName()->getLastName(), $html);
        self::assertStringContainsString('Matemáticas', $html);
        self::assertStringContainsString('tarea.txt', $html);
        self::assertSame(1, substr_count($html, 'tarea.txt'));
    }

    public function testSanctionedStudentsQuickJumpLinkShownWhenSanctionsExist(): void
    {
        $world    = $this->makeWorld('sanctionjump');
        $todayDow = self::todayDayOfWeek();
        $this->persist($this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55'));

        $today = new \DateTimeImmutable('today');
        $this->makeNotifiedSanction($world, $today->modify('-1 day'), $today->modify('+1 day'));

        $admin = $this->makeTeacher('admin-sanctionjump');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="#sanctioned-students"]');
        self::assertSelectorExists('#sanctioned-students');
    }

    public function testSanctionedStudentsQuickJumpLinkHiddenWhenNoSanctions(): void
    {
        $world    = $this->makeWorld('sanctionjumpempty');
        $todayDow = self::todayDayOfWeek();
        $this->persist($this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55'));

        $admin = $this->makeTeacher('admin-sanctionjumpempty');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="#sanctioned-students"]'));
    }

    // ── descarga de adjuntos de actividades ──────────────────────────────────

    public function testGuardCanDownloadActivityAttachment(): void
    {
        [$world, $absence, $activity, $attachment] = $this->makeActivityWithAttachment('activitydownload');
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', $this->activityAttachmentUrl($absence, $activity, $attachment));

        self::assertResponseIsSuccessful();
        self::assertSame('test', $this->client->getResponse()->getContent());
    }

    public function testUnauthorizedTeacherCannotDownloadActivityAttachment(): void
    {
        [$world, $absence, $activity, $attachment] = $this->makeActivityWithAttachment('activitynodownload');
        $unrelated = $this->makeTeacher('unrelated-activity');
        $this->loginAs($unrelated, $world['centre']);

        $this->client->request('GET', $this->activityAttachmentUrl($absence, $activity, $attachment));

        self::assertResponseStatusCodeSame(403);
    }

    public function testActivityAttachmentDownloadReturns404OnMismatchedIds(): void
    {
        [$world, $absence, $activity, $attachment] = $this->makeActivityWithAttachment('activity404');
        [, $otherAbsence, $otherActivity]           = $this->makeActivityWithAttachment('activity404b');
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', $this->activityAttachmentUrl($otherAbsence, $activity, $attachment));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $this->activityAttachmentUrl($absence, $otherActivity, $attachment));
        self::assertResponseStatusCodeSame(404);
    }

    // ── descarga de adjuntos de tareas de sanción ─────────────────────────────

    public function testGuardCanDownloadTaskAttachment(): void
    {
        [$world, $sanction, $task, $attachment] = $this->makeTaskWithAttachment('taskdownload');
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', $this->taskAttachmentUrl($sanction, $task, $attachment));

        self::assertResponseIsSuccessful();
        self::assertSame('test', $this->client->getResponse()->getContent());
    }

    public function testUnauthorizedTeacherCannotDownloadTaskAttachment(): void
    {
        [$world, $sanction, $task, $attachment] = $this->makeTaskWithAttachment('tasknodownload');
        $unrelated = $this->makeTeacher('unrelated-task');
        $this->loginAs($unrelated, $world['centre']);

        $this->client->request('GET', $this->taskAttachmentUrl($sanction, $task, $attachment));

        self::assertResponseStatusCodeSame(403);
    }

    public function testTaskAttachmentDownloadReturns404OnMismatchedIds(): void
    {
        [$world, $sanction, $task, $attachment] = $this->makeTaskWithAttachment('task404');
        [, $otherSanction, $otherTask]           = $this->makeTaskWithAttachment('task404b');
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', $this->taskAttachmentUrl($otherSanction, $task, $attachment));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $this->taskAttachmentUrl($sanction, $otherTask, $attachment));
        self::assertResponseStatusCodeSame(404);
    }

    // ── descargas en zip ──────────────────────────────────────────────────────

    public function testActivityZipDownloadIncludesAllAttachmentsWhenMultiple(): void
    {
        [$world, $absence, $activity] = $this->makeActivityWithAttachments('activityzip', 2);
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', $this->activityAttachmentsZipUrl($absence, $activity));

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
        $entries = $this->readZipEntries();
        self::assertSame('test1', $entries['adjunto-1.txt'] ?? null);
        self::assertSame('test2', $entries['adjunto-2.txt'] ?? null);
    }

    public function testUnauthorizedTeacherCannotDownloadActivityZip(): void
    {
        [$world, $absence, $activity] = $this->makeActivityWithAttachments('activityzipforbidden', 2);
        $unrelated = $this->makeTeacher('unrelated-activityzip');
        $this->loginAs($unrelated, $world['centre']);

        $this->client->request('GET', $this->activityAttachmentsZipUrl($absence, $activity));

        self::assertResponseStatusCodeSame(403);
    }

    public function testActivityZipDownloadReturns404OnMismatchedIds(): void
    {
        [$world, $absence, $activity]       = $this->makeActivityWithAttachments('activityzip404', 2);
        [, $otherAbsence, $otherActivity]   = $this->makeActivityWithAttachments('activityzip404b', 2);
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', $this->activityAttachmentsZipUrl($otherAbsence, $activity));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $this->activityAttachmentsZipUrl($absence, $otherActivity));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTimeSlotZipGroupsAttachmentsByTeacherFolder(): void
    {
        $world    = $this->makeWorld('slotzip');
        $todayDow = self::todayDayOfWeek();
        $today    = new \DateTimeImmutable('today');
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $slot->addGuard($world['teacher']);
        $this->persist($slot);

        $teacherA    = $this->makeTeacherNamed('slotzip-a', 'Ana', 'García');
        $absenceA    = $this->makeAbsence($world['year'], $teacherA, $today);
        $activityA   = $this->makeActivity($absenceA, $slot, $today, '<p>A</p>');
        $attachmentA = new ActivityAttachment($activityA, 'a.txt', 'text/plain', 4, 'contA');
        $activityA->addAttachment($attachmentA);
        $this->persist($absenceA, $activityA, $attachmentA);

        $teacherB    = $this->makeTeacherNamed('slotzip-b', 'Luis', 'Pérez');
        $absenceB    = $this->makeAbsence($world['year'], $teacherB, $today);
        $activityB   = $this->makeActivity($absenceB, $slot, $today, '<p>B</p>');
        $attachmentB = new ActivityAttachment($activityB, 'b.txt', 'text/plain', 4, 'contB');
        $activityB->addAttachment($attachmentB);
        $this->persist($absenceB, $activityB, $attachmentB);

        $this->loginAs($world['teacher'], $world['centre']);
        $this->client->request('GET', $this->timeSlotAttachmentsZipUrl($slot, $today));

        self::assertResponseIsSuccessful();
        $entries = $this->readZipEntries();
        self::assertSame('contA', $entries['García, Ana/a.txt'] ?? null);
        self::assertSame('contB', $entries['Pérez, Luis/b.txt'] ?? null);
    }

    public function testTimeSlotZipIsEmptyButValidWhenNoAttachmentsThatDay(): void
    {
        $world    = $this->makeWorld('slotzipempty');
        $todayDow = self::todayDayOfWeek();
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $slot->addGuard($world['teacher']);
        $this->persist($slot);

        $this->loginAs($world['teacher'], $world['centre']);
        $this->client->request('GET', $this->timeSlotAttachmentsZipUrl($slot, new \DateTimeImmutable('today')));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->readZipEntries());
    }

    public function testUnauthorizedTeacherCannotDownloadTimeSlotZip(): void
    {
        $world    = $this->makeWorld('slotzipforbidden');
        $todayDow = self::todayDayOfWeek();
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $this->persist($slot);

        $unrelated = $this->makeTeacher('unrelated-slotzip');
        $this->loginAs($unrelated, $world['centre']);

        $this->client->request('GET', $this->timeSlotAttachmentsZipUrl($slot, new \DateTimeImmutable('today')));

        self::assertResponseStatusCodeSame(403);
    }

    public function testTimeSlotZipReturns404ForUnknownTimeSlot(): void
    {
        $world = $this->makeWorld('slotzip404');
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', '/guardias/tramos/' . Uuid::v4()->toRfc4122() . '/adjuntos.zip');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSanctionZipAggregatesTasksFromMultipleSubjectsIntoFolders(): void
    {
        $world    = $this->makeWorld('sanctionzipmulti');
        $todayDow = self::todayDayOfWeek();
        $this->persist($this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55')->addGuard($world['teacher']));

        $today    = new \DateTimeImmutable('today');
        $sanction = $this->makeNotifiedSanction($world, $today->modify('-1 day'), $today->modify('+1 day'));

        $taskMaths       = new SanctionTask($sanction, $world['groupTeacher']);
        $attachmentMaths = new SanctionTaskAttachment($taskMaths, 'mates.txt', 'text/plain', 4, 'contM');
        $taskMaths->addAttachment($attachmentMaths);
        $this->persist($taskMaths, $attachmentMaths);

        $historyTeacher = $this->makeTeacher('sanctionzipmulti-history');
        $world['group']->addTeacher($historyTeacher, 'Historia');
        $historyGroupTeacher = $world['group']->getTeacherAssignments()->last();
        self::assertInstanceOf(GroupTeacher::class, $historyGroupTeacher);
        $this->persist($world['group']);

        $taskHistory       = new SanctionTask($sanction, $historyGroupTeacher);
        $attachmentHistory = new SanctionTaskAttachment($taskHistory, 'historia.txt', 'text/plain', 4, 'contH');
        $taskHistory->addAttachment($attachmentHistory);
        $this->persist($taskHistory, $attachmentHistory);

        $this->loginAs($world['teacher'], $world['centre']);
        $this->client->request('GET', $this->sanctionAttachmentsZipUrl($sanction));

        self::assertResponseIsSuccessful();
        $entries = $this->readZipEntries();
        self::assertSame('contM', $entries['Matemáticas/mates.txt'] ?? null);
        self::assertSame('contH', $entries['Historia/historia.txt'] ?? null);
    }

    public function testUnauthorizedTeacherCannotDownloadSanctionZip(): void
    {
        [$world, $sanction] = $this->makeTaskWithAttachment('sanctionzipforbidden');
        $unrelated = $this->makeTeacher('unrelated-sanctionzip');
        $this->loginAs($unrelated, $world['centre']);

        $this->client->request('GET', $this->sanctionAttachmentsZipUrl($sanction));

        self::assertResponseStatusCodeSame(403);
    }

    public function testSanctionZipReturns404ForUnknownSanction(): void
    {
        $world = $this->makeWorld('sanctionzip404');
        $this->loginAs($world['teacher'], $world['centre']);

        $this->client->request('GET', '/guardias/sanciones/' . Uuid::v4()->toRfc4122() . '/adjuntos.zip');

        self::assertResponseStatusCodeSame(404);
    }

    // ── botones de descarga en zip ──────────────────────────────────────────

    public function testActivityZipButtonHiddenForSingleAttachmentShownForMultiple(): void
    {
        [$worldSingle, $absenceSingle, $activitySingle] = $this->makeActivityWithAttachments('activityzipbtn1', 1);
        $adminSingle = $this->makeTeacher('admin-activityzipbtn1');
        $worldSingle['centre']->addAdmin($adminSingle);
        $this->persist($worldSingle['centre']);
        $this->loginAs($adminSingle, $worldSingle['centre']);

        $crawler = $this->client->request('GET', '/guardias');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($this->activityAttachmentsZipUrl($absenceSingle, $activitySingle), $crawler->html());

        [$worldMulti, $absenceMulti, $activityMulti] = $this->makeActivityWithAttachments('activityzipbtn2', 2);
        $adminMulti = $this->makeTeacher('admin-activityzipbtn2');
        $worldMulti['centre']->addAdmin($adminMulti);
        $this->persist($worldMulti['centre']);
        $this->loginAs($adminMulti, $worldMulti['centre']);

        $crawler = $this->client->request('GET', '/guardias');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->activityAttachmentsZipUrl($absenceMulti, $activityMulti), $crawler->html());
    }

    public function testTimeSlotZipButtonHiddenWhenNoAttachmentsThatDay(): void
    {
        $world    = $this->makeWorld('slotzipbtnempty');
        $todayDow = self::todayDayOfWeek();
        $today    = new \DateTimeImmutable('today');
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $this->persist($slot);

        $admin = $this->makeTeacher('admin-slotzipbtnempty');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($this->timeSlotAttachmentsZipUrl($slot, $today), $crawler->html());
    }

    public function testTimeSlotZipButtonShownWhenSlotHasAttachments(): void
    {
        $world    = $this->makeWorld('slotzipbtnfull');
        $todayDow = self::todayDayOfWeek();
        $today    = new \DateTimeImmutable('today');
        $slot     = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $this->persist($slot);

        $absentTeacher = $this->makeTeacher('slotzipbtnfull-absent');
        $absence       = $this->makeAbsence($world['year'], $absentTeacher, $today);
        $activity      = $this->makeActivity($absence, $slot, $today, '<p>Con adjunto.</p>');
        $attachment    = new ActivityAttachment($activity, 'x.txt', 'text/plain', 4, 'test');
        $activity->addAttachment($attachment);
        $this->persist($absence, $activity, $attachment);

        $admin = $this->makeTeacher('admin-slotzipbtnfull');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->timeSlotAttachmentsZipUrl($slot, $today), $crawler->html());
    }

    public function testSanctionZipButtonHiddenWhenNoAttachments(): void
    {
        $world    = $this->makeWorld('sanctionzipbtnempty');
        $todayDow = self::todayDayOfWeek();
        $this->persist($this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55'));

        $today    = new \DateTimeImmutable('today');
        $sanction = $this->makeNotifiedSanction($world, $today->modify('-1 day'), $today->modify('+1 day'));
        $task     = new SanctionTask($sanction, $world['groupTeacher']);
        $task->setDescription('<p>Sin adjuntos.</p>')->setCompletedAt($today);
        $this->persist($task);

        $admin = $this->makeTeacher('admin-sanctionzipbtnempty');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($this->sanctionAttachmentsZipUrl($sanction), $crawler->html());
    }

    public function testSanctionZipButtonShownWhenSanctionHasAttachments(): void
    {
        $world    = $this->makeWorld('sanctionzipbtnfull');
        $todayDow = self::todayDayOfWeek();
        $this->persist($this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55'));

        $today    = new \DateTimeImmutable('today');
        $sanction = $this->makeNotifiedSanction($world, $today->modify('-1 day'), $today->modify('+1 day'));
        $task     = new SanctionTask($sanction, $world['groupTeacher']);
        $task->setDescription('<p>Con adjunto.</p>')->setCompletedAt($today);
        $attachment = new SanctionTaskAttachment($task, 'y.txt', 'text/plain', 4, 'test');
        $task->addAttachment($attachment);
        $this->persist($task, $attachment);

        $admin = $this->makeTeacher('admin-sanctionzipbtnfull');
        $world['centre']->addAdmin($admin);
        $this->persist($world['centre']);
        $this->loginAs($admin, $world['centre']);

        $crawler = $this->client->request('GET', '/guardias');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->sanctionAttachmentsZipUrl($sanction), $crawler->html());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} */
    private function makeWorld(string $suffix): array
    {
        $centre  = (new EducationalCentre())->setCode('42000' . substr(md5($suffix . 'c'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE' . $suffix . uniqid('', false));
        $teacher = $this->makeTeacher($suffix);
        $group->addTeacher($teacher, 'Matemáticas');
        $groupTeacher = $group->getTeacherAssignments()->first();
        self::assertInstanceOf(GroupTeacher::class, $groupTeacher);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student, $teacher);

        return compact('centre', 'year', 'group', 'student', 'teacher', 'groupTeacher');
    }

    private function makeTeacher(string $suffix): Teacher
    {
        return $this->makeTeacherNamed($suffix, 'Test', 'Teacher');
    }

    private function makeTeacherNamed(string $suffix, string $firstName, string $lastName): Teacher
    {
        $teacher = (new Teacher(new PersonName($firstName, $lastName)))
            ->setUsername('teacher.' . $suffix . uniqid('', false))
            ->setEmail('teacher.' . $suffix . '@ejemplo.local');
        $this->persist($teacher);

        return $teacher;
    }

    private function makeTimeSlot(AcademicYear $year, string $name, int $day, string $start, string $end): TimeSlot
    {
        return (new TimeSlot())
            ->setAcademicYear($year)
            ->setName($name)
            ->setDayOfWeek($day)
            ->setStartTime(\DateTimeImmutable::createFromFormat('H:i', $start))
            ->setEndTime(\DateTimeImmutable::createFromFormat('H:i', $end));
    }

    private function makeAbsence(AcademicYear $year, Teacher $teacher, \DateTimeImmutable $date): Absence
    {
        return (new Absence())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setStartDate($date)
            ->setEndDate($date);
    }

    private function makeActivity(Absence $absence, TimeSlot $slot, \DateTimeImmutable $date, string $description): Activity
    {
        $activity = (new Activity())
            ->setDate($date)
            ->setTimeSlot($slot)
            ->setDescription($description);
        $absence->addActivity($activity);

        return $activity;
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher} $world */
    private function makeNotifiedSanction(array $world, \DateTimeImmutable $from, \DateTimeImmutable $to): Sanction
    {
        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($world['teacher'])
            ->setDetails('Detalles de prueba')
            ->setEffectiveFrom($from)
            ->setEffectiveTo($to);
        $this->persist($sanction);

        $method = (new CommunicationMethod())
            ->setEducationalCentre($world['centre'])
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $this->persist($method);

        $communication = Communication::forSanction(
            $sanction,
            $method,
            $world['teacher'],
            new \DateTimeImmutable(),
            CommunicationResult::Notified,
        );
        $this->persist($communication);
        $sanction->setNotifiedCommunication($communication);
        $this->flush();

        return $sanction;
    }

    /** @return array{0: array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher}, 1: Absence, 2: Activity, 3: ActivityAttachment} */
    private function makeActivityWithAttachment(string $suffix): array
    {
        $world    = $this->makeWorld($suffix);
        $todayDow = self::todayDayOfWeek();
        $today    = new \DateTimeImmutable('today');

        $slot = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $slot->addGuard($world['teacher']);
        $this->persist($slot);

        $absence    = $this->makeAbsence($world['year'], $world['teacher'], $today);
        $activity   = $this->makeActivity($absence, $slot, $today, '<p>Actividad.</p>');
        $attachment = new ActivityAttachment($activity, 'adjunto.txt', 'text/plain', 4, 'test');
        $activity->addAttachment($attachment);
        $this->persist($absence, $activity, $attachment);

        return [$world, $absence, $activity, $attachment];
    }

    /** @return array{0: array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher}, 1: Absence, 2: Activity, 3: list<ActivityAttachment>} */
    private function makeActivityWithAttachments(string $suffix, int $count): array
    {
        $world    = $this->makeWorld($suffix);
        $todayDow = self::todayDayOfWeek();
        $today    = new \DateTimeImmutable('today');

        $slot = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $slot->addGuard($world['teacher']);
        $this->persist($slot);

        $absence  = $this->makeAbsence($world['year'], $world['teacher'], $today);
        $activity = $this->makeActivity($absence, $slot, $today, '<p>Actividad.</p>');
        $this->persist($absence, $activity);

        $attachments = [];
        for ($i = 1; $i <= $count; ++$i) {
            $attachment = new ActivityAttachment($activity, sprintf('adjunto-%d.txt', $i), 'text/plain', 5, 'test' . $i);
            $activity->addAttachment($attachment);
            $this->persist($attachment);
            $attachments[] = $attachment;
        }

        return [$world, $absence, $activity, $attachments];
    }

    /** @return array{0: array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, teacher: Teacher, groupTeacher: GroupTeacher}, 1: Sanction, 2: SanctionTask, 3: SanctionTaskAttachment} */
    private function makeTaskWithAttachment(string $suffix): array
    {
        $world    = $this->makeWorld($suffix);
        $todayDow = self::todayDayOfWeek();

        $slot = $this->makeTimeSlot($world['year'], 'Tramo', $todayDow, '08:00', '08:55');
        $slot->addGuard($world['teacher']);
        $this->persist($slot);

        $today    = new \DateTimeImmutable('today');
        $sanction = $this->makeNotifiedSanction($world, $today->modify('-1 day'), $today->modify('+1 day'));
        $task       = new SanctionTask($sanction, $world['groupTeacher']);
        $attachment = new SanctionTaskAttachment($task, 'tarea.txt', 'text/plain', 4, 'test');
        $task->addAttachment($attachment);
        $this->persist($task, $attachment);

        return [$world, $sanction, $task, $attachment];
    }

    private function activityAttachmentUrl(Absence $absence, Activity $activity, ActivityAttachment $attachment): string
    {
        return '/guardias/ausencias/' . $absence->getId()->toRfc4122()
            . '/actividades/' . $activity->getId()->toRfc4122()
            . '/adjuntos/' . $attachment->getId()->toRfc4122();
    }

    private function taskAttachmentUrl(Sanction $sanction, SanctionTask $task, SanctionTaskAttachment $attachment): string
    {
        return '/guardias/sanciones/' . $sanction->getId()->toRfc4122()
            . '/tareas/' . $task->getId()->toRfc4122()
            . '/adjuntos/' . $attachment->getId()->toRfc4122();
    }

    private function activityAttachmentsZipUrl(Absence $absence, Activity $activity): string
    {
        return '/guardias/ausencias/' . $absence->getId()->toRfc4122()
            . '/actividades/' . $activity->getId()->toRfc4122()
            . '/adjuntos.zip';
    }

    private function timeSlotAttachmentsZipUrl(TimeSlot $slot, \DateTimeImmutable $date): string
    {
        return '/guardias/tramos/' . $slot->getId()->toRfc4122() . '/adjuntos.zip?date=' . $date->format('Y-m-d');
    }

    private function sanctionAttachmentsZipUrl(Sanction $sanction): string
    {
        return '/guardias/sanciones/' . $sanction->getId()->toRfc4122() . '/adjuntos.zip';
    }

    /**
     * BinaryFileResponse::getContent() siempre devuelve false; hay que leer el
     * contenido ya volcado por BrowserKit antes de que deleteFileAfterSend()
     * borre el fichero temporal.
     *
     * @return array<string, string>
     */
    private function readZipEntries(): array
    {
        $tempPath = sys_get_temp_dir() . '/' . uniqid('gestconv_test_zip_', true) . '.zip';
        file_put_contents($tempPath, $this->getStreamedContent());

        $zip = new \ZipArchive();
        $zip->open($tempPath);

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            self::assertIsString($name);
            $entries[$name] = (string) $zip->getFromName($name);
        }
        $zip->close();
        unlink($tempPath);

        return $entries;
    }

    private static function todayDayOfWeek(): int
    {
        return ((int) (new \DateTimeImmutable('today'))->format('N')) - 1;
    }

    private function trans(string $key, string $domain): string
    {
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get(TranslatorInterface::class);

        return $translator->trans($key, [], $domain);
    }
}
