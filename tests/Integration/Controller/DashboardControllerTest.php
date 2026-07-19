<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\SettingDefinition;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use App\Tests\Integration\ControllerTestCase;

class DashboardControllerTest extends ControllerTestCase
{
    public function testDashboardRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTutoredGroupsLinksToTutorshipFilteredByThatGroup(): void
    {
        [$teacher, $centre, $group] = $this->makeScenarioWithActiveYear(false);
        $group->addTutor($teacher);
        $this->flush();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/mi-tutoria?groupId=' . $group->getId()->toRfc4122() . '"]');
    }

    public function testDashboardIsAccessibleToAuthenticatedTeacher(): void
    {
        // TenantContextSubscriber redirects to /centro unless a centre is selected.
        $centre  = (new EducationalCentre())->setCode('41000001')->setName('IES Test')->setCity('Sevilla');
        $teacher = (new Teacher(new PersonName('Ana', 'Lopez')))->setUsername('ana.lopez');
        $this->persist($centre, $teacher);
        $centre->addAdmin($teacher);
        $this->flush();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testQuickActionsNewReportIsAlwaysAnActionableLink(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(false);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#quick-actions a[href$="/partes/nuevo"]');
        self::assertCount(0, $crawler->filter('#quick-actions a[href$="/sanciones/nueva"]'));
    }

    public function testQuickActionsNotifyIsDisabledWhenNothingPending(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(false);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#quick-actions a[href$="/notificaciones"]'));
        self::assertSelectorExists('#quick-actions [aria-disabled="true"]');
        self::assertStringContainsString('No hay nada pendiente de notificar', $crawler->filter('#quick-actions [aria-disabled="true"]')->eq(0)->text());
    }

    public function testQuickActionsNotifyIsAnActionableLinkWithPendingCountWhenThereIsSomethingPending(): void
    {
        [$teacher, $centre, $group, $student] = $this->makeScenarioWithActiveYear(false);
        $this->makeUnnotifiedReport($centre, $group, $student, $teacher);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#quick-actions a[href$="/notificaciones"]');
        self::assertStringContainsString('1', $crawler->filter('#quick-actions a[href$="/notificaciones"]')->text());
    }

    public function testQuickActionsNewSanctionIsDisabledForCentreAdminWhenNothingPending(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(true);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#quick-actions a[href$="/sanciones/nueva"]'));
        self::assertStringContainsString('No hay partes pendientes de sancionar', $crawler->filter('#quick-actions [aria-disabled="true"]')->last()->text());
    }

    public function testQuickActionsShowNewSanctionButtonForCentreAdminWhenReportsArePendingSanction(): void
    {
        [$teacher, $centre, $group, $student] = $this->makeScenarioWithActiveYear(true);
        $this->makeNotifiedReport($centre, $group, $student, $teacher);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#quick-actions a[href$="/sanciones/nueva"]');
        self::assertStringContainsString('1', $crawler->filter('#quick-actions a[href$="/sanciones/nueva"]')->text());
    }

    public function testNoStudentsRegisteredShowsNoticeInsteadOfStats(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(true, withStudent: false);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Todavía no hay estudiantes matriculados',
            $crawler->filter('body')->text(),
        );
        self::assertSelectorExists('a[href$="/estudiantes/importar"]');
        $bodyText = $crawler->filter('body')->text();
        self::assertStringNotContainsString('matriculados este curso', $bodyText);
        self::assertStringNotContainsString('Últimos partes', $bodyText);
        self::assertStringNotContainsString('Alumnado con partes pendientes de sanción', $bodyText);
    }

    public function testStudentsRegisteredShowsStatsInsteadOfNotice(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(true, withStudent: true);
        $centre->getActiveAcademicYear()->addTeacher($teacher);
        $this->flush();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/estudiantes/importar"]'));
        self::assertStringContainsString('en los últimos 30 días', $crawler->filter('body')->text());
    }

    public function testNoGroupsDefinedShowsNoticeForAdminInsteadOfStudentsNotice(): void
    {
        $suffix  = uniqid('', false);
        $centre  = (new EducationalCentre())->setCode('41' . substr($suffix, 0, 6))->setName('IES Test')->setCity('Sevilla');
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.' . $suffix)->setAdmin(true);
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $teacher, $year);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $bodyText = $crawler->filter('body')->text();
        self::assertStringContainsString('Todavía no se han definido los grupos', $bodyText);
        self::assertSelectorExists('a[href$="/offer"]');
        self::assertStringNotContainsString('Todavía no hay estudiantes matriculados', $bodyText);
        self::assertStringNotContainsString('Todavía no hay docentes incorporados', $bodyText);
    }

    public function testNoGroupsDefinedShowsNoButtonForNonAdmin(): void
    {
        $suffix  = uniqid('', false);
        $centre  = (new EducationalCentre())->setCode('41' . substr($suffix, 0, 6))->setName('IES Test')->setCity('Sevilla');
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.' . $suffix);
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $teacher, $year);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Todavía no se han definido los grupos', $crawler->filter('body')->text());
    }

    public function testNoTeachersInYearShowsNoticeForAdminWhenGroupsExist(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(true, withStudent: true);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $bodyText = $crawler->filter('body')->text();
        self::assertStringContainsString('Todavía no hay docentes incorporados', $bodyText);
        self::assertSelectorExists('a[href$="/docentes-curso"]');
    }

    public function testTeachersInYearHidesTeacherNotice(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(true, withStudent: true);
        $centre->getActiveAcademicYear()->addTeacher($teacher);
        $this->flush();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Todavía no hay docentes incorporados', $crawler->filter('body')->text());
    }

    public function testPendingSanctionTasksCardShowsCountForSubjectTeacher(): void
    {
        [$teacher, $centre, $group, $student] = $this->makeScenarioWithActiveYear(false);
        $group->addTeacher($teacher, 'Matemáticas');
        $this->flush();
        $groupTeacher = $group->getTeacherAssignments()->first();

        $sanction = (new Sanction())
            ->setAcademicYear($centre->getActiveAcademicYear())
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($teacher)
            ->setDetails('Detalles de prueba.')
            ->setNoMeasureApplied(false);
        $this->persist($sanction);
        $this->persist(new SanctionTask($sanction, $groupTeacher));
        $this->flush();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href$="/tareas-de-sancion"]');
        self::assertStringContainsString('1', $crawler->filter('a[href$="/tareas-de-sancion"]')->last()->text());
    }

    public function testPendingSanctionTasksCardIsHiddenForNonTeachingTeacher(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(false);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/tareas-de-sancion"]'));
    }

    public function testSanctionsWithIncompleteTasksCardShowsCountForAdmin(): void
    {
        [$teacher, $centre, $group, $student] = $this->makeScenarioWithActiveYear(true);
        $other = $this->makeTeacher('sanction.tasks.subject');
        $this->persist($other);
        $group->addTeacher($other, 'Matemáticas');
        $this->flush();
        $groupTeacher = $group->getTeacherAssignments()->first();

        $sanction = (new Sanction())
            ->setAcademicYear($centre->getActiveAcademicYear())
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($teacher)
            ->setDetails('Detalles de prueba.')
            ->setNoMeasureApplied(false);
        $this->persist($sanction);
        $this->persist(new SanctionTask($sanction, $groupTeacher));
        $this->flush();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href$="/sanciones?pendingTasksOnly=1"]');
        self::assertStringContainsString('1', $crawler->filter('a[href$="/sanciones?pendingTasksOnly=1"]')->text());
    }

    public function testQuickActionsPendingTasksIsHiddenForNonTeachingTeacher(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(false);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#quick-actions a[href$="/tareas-de-sancion"]'));
    }

    public function testQuickActionsPendingTasksIsAnActionableLinkWithCountForSubjectTeacher(): void
    {
        [$teacher, $centre, $group, $student] = $this->makeScenarioWithActiveYear(false);
        $group->addTeacher($teacher, 'Matemáticas');
        $this->flush();
        $groupTeacher = $group->getTeacherAssignments()->first();

        $sanction = (new Sanction())
            ->setAcademicYear($centre->getActiveAcademicYear())
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($teacher)
            ->setDetails('Detalles de prueba.')
            ->setNoMeasureApplied(false);
        $this->persist($sanction);
        $this->persist(new SanctionTask($sanction, $groupTeacher));
        $this->flush();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#quick-actions a[href$="/tareas-de-sancion"]');
        self::assertStringContainsString('1', $crawler->filter('#quick-actions a[href$="/tareas-de-sancion"]')->text());
    }

    public function testQuickActionsNewAbsenceIsShownForTeacherBelongingToViewYear(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(false);
        $centre->getActiveAcademicYear()->addTeacher($teacher);
        $this->flush();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#quick-actions a[href$="/ausencias/nuevo"]');
        self::assertStringContainsString('Anotar ausencia propia', $crawler->filter('#quick-actions a[href$="/ausencias/nuevo"]')->text());
    }

    public function testQuickActionsNewAbsenceShowsAdminWordingForCentreAdmin(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(true);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#quick-actions a[href$="/ausencias/nuevo"]');
        self::assertStringContainsString('Anotar ausencia de un docente', $crawler->filter('#quick-actions a[href$="/ausencias/nuevo"]')->text());
    }

    public function testQuickActionsGuardsIsHiddenForTeacherWithoutGuardDuty(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(false);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#quick-actions a[href$="/guardias"]'));
    }

    public function testQuickActionsGuardsIsShownForTeacherWithGuardDuty(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(false);
        $slot = (new TimeSlot())
            ->setAcademicYear($centre->getActiveAcademicYear())
            ->setName('1ª hora')
            ->setDayOfWeek(0)
            ->setStartTime(\DateTimeImmutable::createFromFormat('H:i', '08:00'))
            ->setEndTime(\DateTimeImmutable::createFromFormat('H:i', '08:55'));
        $slot->addGuard($teacher);
        $this->persist($slot);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#quick-actions a[href$="/guardias"]');
    }

    public function testQuickActionsGuardsIsHiddenForAdminWithoutGuardDuty(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(true);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#quick-actions a[href$="/guardias"]'));
    }

    public function testPendingPrescriptionCardShowsZeroWhenNothingPending(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(true);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Partes próximos a prescribir', $crawler->filter('body')->text());
        self::assertStringContainsString('ninguno cerca del plazo de prescripción', $crawler->filter('body')->text());
    }

    public function testPendingPrescriptionCardCountsUnnotifiedReportsNearingCutoff(): void
    {
        [$teacher, $centre, $group, $student] = $this->makeScenarioWithActiveYear(true);
        // Ajustes por defecto: 14 días para prescribir, aviso con 7 días de antelación → corte a los 7 días.
        $this->makeUnnotifiedReport($centre, $group, $student, $teacher, new \DateTimeImmutable('-8 days'));
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $bodyText = $crawler->filter('body')->text();
        self::assertStringContainsString('Partes próximos a prescribir', $bodyText);
        self::assertStringContainsString('sin notificar, cerca del plazo de prescripción automática', $bodyText);
    }

    public function testPendingPrescriptionCardIsHiddenWhenAutoPrescriptionDisabled(): void
    {
        [$teacher, $centre] = $this->makeScenarioWithActiveYear(true);
        $this->setCentreIntegerSetting('notifications.report_auto_prescribe_days', $centre, 0);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Partes próximos a prescribir', $crawler->filter('body')->text());
    }

    private function setCentreIntegerSetting(string $key, EducationalCentre $centre, int $value): void
    {
        /** @var SettingDefinition $definition */
        $definition = $this->em->getRepository(SettingDefinition::class)->findOneBy(['key' => $key]);

        $centreValue = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue((string) $value);
        $this->persist($centreValue);
        $this->flush();
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student} */
    private function makeScenarioWithActiveYear(bool $isAdmin, bool $withStudent = true): array
    {
        $suffix    = uniqid('', false);
        $centre    = (new EducationalCentre())->setCode('41' . substr($suffix, 0, 6))->setName('IES Test')->setCity('Sevilla');
        $teacher   = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.' . $suffix)->setAdmin($isAdmin);
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA')->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . $suffix);
        $centre->setActiveAcademicYear($year);

        if ($withStudent) {
            $group->addStudent($student);
        }

        $this->persist($centre, $teacher, $year, $course, $group, $student);

        return [$teacher, $centre, $group, $student];
    }

    private function makeUnnotifiedReport(EducationalCentre $centre, Group $group, Student $student, Teacher $creator, ?\DateTimeImmutable $occurredAt = null): IncidentReport
    {
        [$category, $behavior] = $this->makeBehavior($centre);
        $report = (new IncidentReport())
            ->setAcademicYear($centre->getActiveAcademicYear())
            ->setNumber(1)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt($occurredAt ?? new \DateTimeImmutable())
            ->setDescription('<p>Test.</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);
        $this->persist($category, $behavior, $report);
        $this->flush();

        return $report;
    }

    private function makeNotifiedReport(EducationalCentre $centre, Group $group, Student $student, Teacher $creator): IncidentReport
    {
        $report = $this->makeUnnotifiedReport($centre, $group, $student, $creator);
        $method = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $communication = Communication::forIncidentReport(
            $report, $method, $creator, new \DateTimeImmutable(), CommunicationResult::Notified,
        );
        $this->persist($method, $communication);
        $report->setNotifiedCommunication($communication);
        $this->flush();

        return $report;
    }

    /** @return array{0: IncidentBehaviorCategory, 1: IncidentBehavior} */
    private function makeBehavior(EducationalCentre $centre): array
    {
        $category = (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName('Contrarias')
            ->setSerious(false)
            ->setPosition(0);
        $behavior = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Perturbación del normal desarrollo de las actividades')
            ->setPosition(0)
            ->setActive(true);

        return [$category, $behavior];
    }
}
