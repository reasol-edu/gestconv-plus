<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentReport;
use App\Entity\IncidentReportObservation;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class IncidentReportActivityLogTest extends ControllerTestCase
{
    private static int $scenarioCounter = 0;

    protected function setUp(): void
    {
        putenv('APP_LOG=true');
        $_ENV['APP_LOG']    = 'true';
        $_SERVER['APP_LOG'] = 'true';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('APP_LOG=false');
        $_ENV['APP_LOG']    = 'false';
        $_SERVER['APP_LOG'] = 'false';
    }

    public function testCreatingReportLogsIncidentReportCreated(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $location = $this->makeLocation($centre);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $studentPair = $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'              => $token,
            'students'            => [$studentPair],
            'behaviors'           => [$behavior->getId()->toRfc4122()],
            'location_id'         => $location->getId()->toRfc4122(),
            'occurred_at'         => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description'         => '<p>Incidente de prueba.</p>',
            'expelled_from_class' => '0',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_report.created', $logs[0]->getActionType());
        self::assertSame($student->getId()->toRfc4122(), $logs[0]->getData()['studentId'] ?? null);
    }

    public function testEditingReportLogsIncidentReportUpdatedWithDiff(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $teacher, $behavior);
        $location = $this->makeLocation($centre);
        $this->loginAs($teacher, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/partes/' . $reportId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/editar', [
            '_token'              => $token,
            'behaviors'           => [$behavior->getId()->toRfc4122()],
            'location_id'         => $location->getId()->toRfc4122(),
            'occurred_at'         => $report->getOccurredAt()->format('Y-m-d\TH:i'),
            'description'         => '<p>Descripción modificada.</p>',
            'expelled_from_class' => '0',
        ]);

        self::assertResponseRedirects('/partes/' . $reportId);

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_report.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('<p>Test.</p>', $changes['description']['before'] ?? null);
        self::assertSame('<p>Descripción modificada.</p>', $changes['description']['after'] ?? null);
    }

    public function testDeletingReportLogsIncidentReportDeleted(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $teacher->setAdmin(true);
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($teacher, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/partes/' . $reportId);
        $token    = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/partes');

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_report.deleted', $logs[0]->getActionType());
        self::assertSame($reportId, $logs[0]->getData()['entityId'] ?? null);
    }

    public function testAddingObservationLogsObservationCreatedAndSuppressesGenericLog(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($teacher, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/partes/' . $reportId);
        $token    = $crawler->filter('form[action$="/observaciones"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/observaciones', [
            '_token' => $token,
            'text'   => '<p>Nueva observación.</p>',
        ]);

        self::assertResponseRedirects('/partes/' . $reportId);

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        // Only the curated entry must exist — no duplicate generic route/method log.
        self::assertCount(1, $logs);
        self::assertSame('incident_report_observation.created', $logs[0]->getActionType());
        self::assertSame($reportId, $logs[0]->getData()['reportId'] ?? null);
    }

    public function testEditingObservationLogsDiff(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report      = $this->makeReport($student, $group, $teacher, $behavior);
        $observation = new IncidentReportObservation($report, $teacher, new \DateTimeImmutable(), '<p>Original.</p>');
        $this->persist($observation);
        $this->loginAs($teacher, $centre);

        $reportId      = $report->getId()->toRfc4122();
        $observationId = $observation->getId()->toRfc4122();
        $crawler       = $this->client->request('GET', '/partes/' . $reportId . '/observaciones/' . $observationId . '/editar');
        $token         = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/observaciones/' . $observationId . '/editar', [
            '_token' => $token,
            'text'   => '<p>Modificada.</p>',
        ]);

        self::assertResponseRedirects('/partes/' . $reportId);

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_report_observation.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('<p>Original.</p>', $changes['text']['before'] ?? null);
        self::assertSame('<p>Modificada.</p>', $changes['text']['after'] ?? null);
    }

    public function testDeletingObservationLogsIt(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report      = $this->makeReport($student, $group, $teacher, $behavior);
        $observation = new IncidentReportObservation($report, $teacher, new \DateTimeImmutable(), '<p>Original.</p>');
        $this->persist($observation);
        $this->loginAs($teacher, $centre);

        $reportId      = $report->getId()->toRfc4122();
        $observationId = $observation->getId()->toRfc4122();
        $crawler       = $this->client->request('GET', '/partes/' . $reportId);
        $token         = $crawler->filter('form[action$="/observaciones/' . $observationId . '/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/observaciones/' . $observationId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/partes/' . $reportId);

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('incident_report_observation.deleted', $logs[0]->getActionType());
        self::assertSame($observationId, $logs[0]->getData()['entityId'] ?? null);
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student, 4: IncidentBehavior} */
    private function makeScenario(): array
    {
        $suffix    = (string) ++self::$scenarioCounter;
        $teacher   = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.' . uniqid('', false) . $suffix);
        $centre    = (new EducationalCentre())->setCode('5' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
        $year      = (new \App\Entity\AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new \App\Entity\Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA')->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . uniqid('', false));
        $category  = (new \App\Entity\IncidentBehaviorCategory())
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

        $centre->setActiveAcademicYear($year);
        $this->persist($teacher, $centre, $year, $course, $group, $student, $category, $behavior);

        return [$teacher, $centre, $group, $student, $behavior];
    }

    private function makeLocation(EducationalCentre $centre, string $name = 'Aula'): LocationOption
    {
        $category = (new LocationOptionCategory())
            ->setEducationalCentre($centre)
            ->setName('General')
            ->setPosition(0);
        $this->persist($category);

        $location = (new LocationOption())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition(0)
            ->setActive(true);
        $this->persist($location);

        return $location;
    }

    private function makeReport(
        Student $student,
        Group $group,
        Teacher $creator,
        IncidentBehavior $behavior,
    ): IncidentReport {
        $academicYear = $group->getCourse()->getAcademicYear();

        $report = (new IncidentReport())
            ->setAcademicYear($academicYear)
            ->setNumber(1)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test.</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);

        $this->persist($report);

        return $report;
    }
}
