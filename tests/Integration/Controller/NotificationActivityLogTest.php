<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\ActivityLog;
use App\Entity\CommunicationMethod;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class NotificationActivityLogTest extends ControllerTestCase
{
    private int $nextReportNumber = 0;

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

    public function testRegisteringForReportLogsCommunicationRegistered(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $this->loginAs($creator, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/notificaciones/partes/' . $reportId . '/registrar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/partes/' . $reportId . '/registrar', [
            '_token'       => $token,
            'method_id'    => $method->getId()->toRfc4122(),
            'performed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'result'       => 'notified',
            'description'  => 'Llamada realizada a la familia.',
        ]);

        self::assertResponseRedirects('/notificaciones');

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('communication.registered', $logs[0]->getActionType());
        self::assertSame($reportId, $logs[0]->getData()['reportId'] ?? null);
        self::assertSame('notified', $logs[0]->getData()['result'] ?? null);
    }

    public function testRegisteringForSanctionLogsCommunicationRegistered(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $sanction = $this->makeSanction($student, $group, $creator);
        $this->persist($sanction);
        $this->loginAs($creator, $centre);

        $sanctionId = $sanction->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/notificaciones/sanciones/' . $sanctionId . '/registrar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/sanciones/' . $sanctionId . '/registrar', [
            '_token'       => $token,
            'method_id'    => $method->getId()->toRfc4122(),
            'performed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'result'       => 'notified',
            'description'  => 'Comunicada por correo.',
        ]);

        self::assertResponseRedirects('/notificaciones');

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('communication.registered', $logs[0]->getActionType());
        self::assertSame($sanctionId, $logs[0]->getData()['sanctionId'] ?? null);
    }

    public function testRegisteringForStudentReportsLogsOneEntryPerReport(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report1 = $this->makeReport($student, $group, $creator, $behavior);
        $report2 = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report1, $report2);
        $this->loginAs($creator, $centre);

        $studentId = $student->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/notificaciones/partes/estudiante/' . $studentId . '/registrar');
        $token     = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/partes/estudiante/' . $studentId . '/registrar', [
            '_token'       => $token,
            'report_ids'   => [$report1->getId()->toRfc4122(), $report2->getId()->toRfc4122()],
            'method_id'    => $method->getId()->toRfc4122(),
            'performed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'result'       => 'notified',
            'description'  => 'Llamada conjunta a la familia.',
        ]);

        self::assertResponseRedirects('/notificaciones');

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(2, $logs);
        foreach ($logs as $log) {
            self::assertSame('communication.registered', $log->getActionType());
        }
        $reportIds = array_map(static fn (ActivityLog $l): mixed => $l->getData()['reportId'] ?? null, $logs);
        self::assertContains($report1->getId()->toRfc4122(), $reportIds);
        self::assertContains($report2->getId()->toRfc4122(), $reportIds);
    }

    /**
     * @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student, 4: IncidentBehavior, 5: CommunicationMethod}
     */
    private function makeScenario(): array
    {
        $teacher   = $this->makeTeacher('teacher.' . uniqid('', false));
        $centre    = (new EducationalCentre())->setCode('71' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA')->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . uniqid('', false));
        $category  = (new IncidentBehaviorCategory())
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
        $method = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);

        $centre->setActiveAcademicYear($year);
        $this->persist($teacher, $centre, $year, $course, $group, $student, $category, $behavior, $method);

        return [$teacher, $centre, $group, $student, $behavior, $method];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
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
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test.</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);

        return $report;
    }

    private function makeSanction(Student $student, Group $group, Teacher $creator): Sanction
    {
        $academicYear = $group->getCourse()->getAcademicYear();

        return (new Sanction())
            ->setAcademicYear($academicYear)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setDetails('Detalle de la sanción de prueba.')
            ->setNoMeasureApplied(false);
    }
}
