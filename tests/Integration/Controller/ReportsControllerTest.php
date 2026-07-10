<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
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

class ReportsControllerTest extends ControllerTestCase
{
    private int $nextNumber = 0;

    public function testIndexRendersHubForCentreAdmin(): void
    {
        [$admin, $centre] = $this->makeScenario('41100001');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/informes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Informes');
    }

    public function testIndexDeniesAccessToUnprivilegedTeacher(): void
    {
        [, $centre] = $this->makeScenario('41100002');
        $teacher = $this->makeTeacher('unprivileged.reports');
        $this->persist($teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/informes');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGroupStatsRendersFormWithoutRange(): void
    {
        [$admin, $centre] = $this->makeScenario('41100003');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/informes/estadisticas-grupo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
        self::assertSelectorNotExists('table');
    }

    public function testGroupStatsRendersResultsForValidRange(): void
    {
        [$admin, $centre, $year, $group] = $this->makeScenario('41100004');
        $this->makeIncidentReport($year, $group, $admin);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/informes/estadisticas-grupo', [
            'from' => '2026-01-01',
            'to'   => '2026-06-30',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table');
        self::assertSelectorTextContains('table', $group->getName());
    }

    public function testGroupStatsPdfReturnsAPdfDocument(): void
    {
        [$admin, $centre, $year, $group] = $this->makeScenario('41100005');
        $this->makeIncidentReport($year, $group, $admin);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/informes/estadisticas-grupo/pdf', [
            'from' => '2026-01-01',
            'to'   => '2026-06-30',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    public function testGroupStatsPdfReturns404WithoutRange(): void
    {
        [$admin, $centre] = $this->makeScenario('41100006');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/informes/estadisticas-grupo/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGroupStatsXlsxReturnsASpreadsheet(): void
    {
        [$admin, $centre, $year, $group] = $this->makeScenario('41100007');
        $this->makeIncidentReport($year, $group, $admin);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/informes/estadisticas-grupo/excel', [
            'from' => '2026-01-01',
            'to'   => '2026-06-30',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $this->client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testGroupStatsIsDeniedToUnprivilegedTeacher(): void
    {
        [, $centre] = $this->makeScenario('41100008');
        $teacher = $this->makeTeacher('unprivileged.reports.stats');
        $this->persist($teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/informes/estadisticas-grupo');

        self::assertResponseStatusCodeSame(403);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear, 3: Group}
     */
    private function makeScenario(string $code): array
    {
        $admin  = (new Teacher(new PersonName('Admin', 'Reports')))->setUsername('admin.reports.' . $code)->setAdmin(true);
        $centre = (new EducationalCentre())->setCode($code)->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);

        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $py        = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA')->setProgrammeYear($py);

        $this->persist($admin, $centre, $year, $programme, $py, $group);

        return [$admin, $centre, $year, $group];
    }

    private function makeIncidentReport(AcademicYear $year, Group $group, Teacher $teacher): void
    {
        $student = (new Student(new PersonName('Test', 'Student')))->setStudentId('NIE-rc-' . (++$this->nextNumber));

        $category = (new IncidentBehaviorCategory())
            ->setEducationalCentre($year->getEducationalCentre())
            ->setName('Leves')->setSerious(false)->setPosition(0);
        $behavior = (new IncidentBehavior())
            ->setEducationalCentre($year->getEducationalCentre())
            ->setCategory($category)->setName('Falta leve')->setPosition(0)->setActive(true);

        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber($this->nextNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($teacher)
            ->setOccurredAt(new \DateTimeImmutable('2026-03-15'))
            ->setDescription('<p>Test</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);

        $this->persist($student, $category, $behavior, $report);
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }
}
