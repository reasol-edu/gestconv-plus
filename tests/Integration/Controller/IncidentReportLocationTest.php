<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class IncidentReportLocationTest extends ControllerTestCase
{
    private static int $scenarioCounter = 0;
    private int $nextReportNumber = 0;

    // ── search endpoint ──────────────────────────────────────────────────────

    public function testSearchLocationsReturnsMatchingActiveOption(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $location = $this->makeLocation($centre, 'Recreo');
        $this->persist($location);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/buscar-ubicaciones?q=recr');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('Recreo', $data[0]['label']);
        self::assertSame($location->getId()->toRfc4122(), $data[0]['value']);
    }

    public function testSearchLocationsExcludesInactiveOptions(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $location = $this->makeLocation($centre, 'Recreo antiguo', false);
        $this->persist($location);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/buscar-ubicaciones?q=recreo');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $data);
    }

    public function testSearchLocationsWithEmptyQueryReturnsFullList(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $location = $this->makeLocation($centre, 'Recreo');
        $this->persist($location);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/buscar-ubicaciones');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('Recreo', $data[0]['label']);
        self::assertSame($location->getCategory()->getId()->toRfc4122(), $data[0]['category']);
    }

    // ── new ───────────────────────────────────────────────────────────────────

    public function testNewPostWithLocationSetsLocationOnReport(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $location = $this->makeLocation($centre, 'En clase');
        $this->persist($location);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $studentPair = $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'      => $token,
            'students'    => [$studentPair],
            'behaviors'   => [$behavior->getId()->toRfc4122()],
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description' => '<p>Incidente de prueba.</p>',
            'location_id' => $location->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $reports = $this->em->getRepository(IncidentReport::class)->findAll();
        self::assertCount(1, $reports);
        self::assertNotNull($reports[0]->getLocation());
        self::assertSame('En clase', $reports[0]->getLocation()->getName());
    }

    public function testNewPostWithoutLocationShowsRequiredError(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $studentPair = $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'      => $token,
            'students'    => [$studentPair],
            'behaviors'   => [$behavior->getId()->toRfc4122()],
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description' => '<p>Incidente de prueba.</p>',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'dónde sucedió');

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(IncidentReport::class)->findAll());
    }

    public function testNewPostWithLocationFromAnotherCentreShowsRequiredError(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        [, $otherCentre] = $this->makeScenario();
        $foreignLocation = $this->makeLocation($otherCentre, 'Ubicación de otro centro');
        $this->persist($foreignLocation);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $studentPair = $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'      => $token,
            'students'    => [$studentPair],
            'behaviors'   => [$behavior->getId()->toRfc4122()],
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description' => '<p>Incidente de prueba.</p>',
            'location_id' => $foreignLocation->getId()->toRfc4122(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'dónde sucedió');

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(IncidentReport::class)->findAll());
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditPostUpdatesLocation(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $location = $this->makeLocation($centre, 'Entrada/Salida');
        $this->persist($location);
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($teacher, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/partes/' . $reportId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/editar', [
            '_token'              => $token,
            'behaviors'           => [$behavior->getId()->toRfc4122()],
            'occurred_at'         => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description'         => '<p>Test.</p>',
            'expelled_from_class' => '0',
            'location_id'         => $location->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $updated = $this->em->find(IncidentReport::class, $report->getId());
        self::assertNotNull($updated);
        self::assertNotNull($updated->getLocation());
        self::assertSame('Entrada/Salida', $updated->getLocation()->getName());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student, 4: IncidentBehavior} */
    private function makeScenario(): array
    {
        $suffix    = (string) ++self::$scenarioCounter;
        $teacher   = $this->makeTeacher('teacher.loc.' . uniqid('', false) . $suffix);
        $centre    = (new EducationalCentre())->setCode('5' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
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

        $centre->setActiveAcademicYear($year);
        $this->persist($teacher, $centre, $year, $programme, $level, $group, $student, $category, $behavior);

        return [$teacher, $centre, $group, $student, $behavior];
    }

    private function makeLocation(EducationalCentre $centre, string $name, bool $active = true): LocationOption
    {
        $category = (new LocationOptionCategory())
            ->setEducationalCentre($centre)
            ->setName('General')
            ->setPosition(0);
        $this->persist($category);

        return (new LocationOption())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName($name)
            ->setPosition(0)
            ->setActive($active);
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
}
