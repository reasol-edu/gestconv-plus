<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\ActivityLog;
use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Sanction;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class SanctionActivityLogTest extends ControllerTestCase
{
    private int $nextReportNumber = 0;
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

    public function testCreatingSanctionLogsSanctionCreated(): void
    {
        [$admin, $centre, $group, $student, $behavior, $measure] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $behavior);
        $this->loginAs($admin, $centre);

        $url     = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
                 . '&groupId=' . $group->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $url);
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'   => $token,
            'reports'  => [$report->getId()->toRfc4122()],
            'measures' => [$measure->getId()->toRfc4122()],
            'details'  => 'Motivo de la sanción.',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction.created', $logs[0]->getActionType());
        self::assertSame($student->getId()->toRfc4122(), $logs[0]->getData()['studentId'] ?? null);
        self::assertSame([$report->getId()->toRfc4122()], $logs[0]->getData()['reportIds'] ?? null);
    }

    public function testEditingSanctionLogsSanctionUpdatedWithDiff(): void
    {
        [$admin, $centre, $group, $student, $behavior, $measure] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $sanctionId = $sanction->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/sanciones/' . $sanctionId . '/editar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/sanciones/' . $sanctionId . '/editar', [
            '_token'   => $token,
            'reports'  => [$report->getId()->toRfc4122()],
            'measures' => [$measure->getId()->toRfc4122()],
            'details'  => 'Descripción actualizada.',
        ]);

        self::assertResponseRedirects('/sanciones/' . $sanctionId);

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('Detalles de prueba.', $changes['details']['before'] ?? null);
        self::assertSame('Descripción actualizada.', $changes['details']['after'] ?? null);
    }

    public function testDeletingSanctionLogsSanctionDeleted(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $sanctionId = $sanction->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/sanciones/' . $sanctionId);
        $token      = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/sanciones/' . $sanctionId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/sanciones');

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('sanction.deleted', $logs[0]->getActionType());
        self::assertSame($sanctionId, $logs[0]->getData()['entityId'] ?? null);
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student, 4: IncidentBehavior, 5: SanctionMeasure} */
    private function makeScenario(): array
    {
        $suffix    = (string) ++self::$scenarioCounter;
        $admin     = $this->makeTeacher('cadmin.' . uniqid('', false) . $suffix);
        $centre    = (new EducationalCentre())->setCode('6' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA')->setProgrammeYear($level);
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
        $measureCat = (new SanctionMeasureCategory())
            ->setEducationalCentre($centre)
            ->setName('Correcciones')
            ->setPosition(0);
        $measure = (new SanctionMeasure())
            ->setEducationalCentre($centre)
            ->setCategory($measureCat)
            ->setName('Amonestación oral')
            ->setHasDateRange(false)
            ->setPosition(0)
            ->setActive(true);

        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        $group->addStudent($student);
        $this->persist($admin, $centre, $year, $programme, $level, $group, $student, $category, $behavior, $measureCat, $measure);

        return [$admin, $centre, $group, $student, $behavior, $measure];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function makeReport(
        Student $student,
        Group $group,
        IncidentBehavior $behavior,
        ?Teacher $creator = null,
    ): IncidentReport {
        if ($creator === null) {
            $creator = $this->makeTeacher('default.creator.' . uniqid('', false));
            $this->persist($creator);
        }

        $academicYear = $group->getProgrammeYear()->getProgramme()->getAcademicYear();

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

        $this->persist($report);

        return $report;
    }

    /**
     * @param list<IncidentReport> $reports
     */
    private function makeSanction(
        Teacher $registeredBy,
        Student $student,
        Group $group,
        array $reports = [],
    ): Sanction {
        $academicYear = $group->getProgrammeYear()->getProgramme()->getAcademicYear();

        $sanction = (new Sanction())
            ->setAcademicYear($academicYear)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($registeredBy)
            ->setDetails('Detalles de prueba.')
            ->setNoMeasureApplied(false);

        $this->persist($sanction);

        foreach ($reports as $report) {
            $report->setSanction($sanction);
        }
        $this->flush();

        return $sanction;
    }
}
