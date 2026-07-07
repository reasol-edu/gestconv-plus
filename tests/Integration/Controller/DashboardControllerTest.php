<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class DashboardControllerTest extends ControllerTestCase
{
    public function testDashboardRedirectsAnonymousUserToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
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
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/estudiantes/importar"]'));
        self::assertStringContainsString('matriculados este curso', $crawler->filter('body')->text());
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student} */
    private function makeScenarioWithActiveYear(bool $isAdmin, bool $withStudent = true): array
    {
        $suffix    = uniqid('', false);
        $centre    = (new EducationalCentre())->setCode('41' . substr($suffix, 0, 6))->setName('IES Test')->setCity('Sevilla');
        $teacher   = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.' . $suffix)->setAdmin($isAdmin);
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA')->setProgrammeYear($level);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . $suffix);
        $centre->setActiveAcademicYear($year);

        if ($withStudent) {
            $group->addStudent($student);
        }

        $this->persist($centre, $teacher, $year, $programme, $level, $group, $student);

        return [$teacher, $centre, $group, $student];
    }

    private function makeUnnotifiedReport(EducationalCentre $centre, Group $group, Student $student, Teacher $creator): IncidentReport
    {
        [$category, $behavior] = $this->makeBehavior($centre);
        $report = (new IncidentReport())
            ->setAcademicYear($centre->getActiveAcademicYear())
            ->setNumber(1)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
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
