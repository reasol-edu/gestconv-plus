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
use App\Entity\Course;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class StudentControllerTest extends ControllerTestCase
{
    private static int $scenarioCounter = 0;

    private int $nextReportNumber = 0;

    public function testShowRedirectsAnonymousUser(): void
    {
        $this->client->request('GET', '/alumnado/00000000-0000-0000-0000-000000000000');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testShowDisplaysHistoryAndContactForCentreAdmin(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $cadmin = $this->makeTeacher('student.cadmin.1');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $student->setTutorName1('María Tutora');
        $report   = $this->makeReport($student, $group, $teacher, $behavior);
        $sanction = $this->makeSanction($student, $group, $teacher);
        $this->flush();

        $this->loginAs($cadmin, $centre);
        $this->client->request('GET', '/alumnado/' . $student->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('García, Ana', $content);
        self::assertStringContainsString('Historial de convivencia', $content);
        self::assertStringContainsString('Datos de contacto', $content);
        self::assertStringContainsString('María Tutora', $content);
        self::assertStringContainsString('/partes/' . $report->getId()->toRfc4122(), $content);
        self::assertStringContainsString('/sanciones/' . $sanction->getId()->toRfc4122(), $content);
    }

    public function testShowHidesOtherTeachersReportsAndContactFromPlainTeacher(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $other = $this->makeTeacher('student.plain.2');
        $group->addTeacher($other);
        $student->setTutorName1('María Tutora');
        $this->persist($other);
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->flush();

        $this->loginAs($other, $centre);
        $this->client->request('GET', '/alumnado/' . $student->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('García, Ana', $content);
        self::assertStringContainsString('no tiene partes ni sanciones visibles', $content);
        self::assertStringNotContainsString('/partes/' . $report->getId()->toRfc4122(), $content);
        self::assertStringNotContainsString('Datos de contacto', $content);
        self::assertStringNotContainsString('María Tutora', $content);
    }

    public function testShowDisplaysHistoryAndContactForGroupTutor(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $tutor = $this->makeTeacher('student.tutor.3');
        $group->addTutor($tutor);
        $student->setTutorName1('María Tutora');
        $this->persist($tutor);
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->flush();

        $this->loginAs($tutor, $centre);
        $this->client->request('GET', '/alumnado/' . $student->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/partes/' . $report->getId()->toRfc4122(), $content);
        self::assertStringContainsString('Datos de contacto', $content);
        self::assertStringContainsString('María Tutora', $content);
    }

    public function testShowReturns404ForStudentOfAnotherCentre(): void
    {
        [, $centreA] = $this->makeScenario();
        $cadminA = $this->makeTeacher('student.cadmin.4');
        $this->persist($cadminA);
        $centreA->addAdmin($cadminA);
        $this->flush();

        [, , , $studentB] = $this->makeScenario();

        $this->loginAs($cadminA, $centreA);
        $this->client->request('GET', '/alumnado/' . $studentB->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowOnlyDisplaysReportsAndSanctionsOfViewedAcademicYear(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $cadmin = $this->makeTeacher('student.cadmin.5');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $currentYearReport = $this->makeReport($student, $group, $teacher, $behavior);
        $this->flush();

        $pastYear      = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $pastCourse = (new Course())->setName('DAW-Y0')->setAcademicYear($pastYear);
        $pastGroup  = (new Group())->setName('1ºA-Y0')->setCourse($pastCourse);
        $pastGroup->addStudent($student);
        $this->persist($pastYear, $pastCourse, $pastGroup);
        $pastReport = $this->makeReport($student, $pastGroup, $teacher, $behavior);
        $this->flush();

        $this->loginAs($cadmin, $centre);
        $this->viewPastYear($pastYear);
        $this->client->request('GET', '/alumnado/' . $student->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('/partes/' . $pastReport->getId()->toRfc4122(), $content);
        self::assertStringNotContainsString('/partes/' . $currentYearReport->getId()->toRfc4122(), $content);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student, 4: IncidentBehavior}
     */
    private function makeScenario(): array
    {
        $suffix    = (string) ++self::$scenarioCounter;
        $teacher   = $this->makeTeacher('student.teacher.' . uniqid('', false) . $suffix);
        $centre    = (new EducationalCentre())->setCode('5' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA')->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . uniqid('', false));
        $category  = (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName('Contrarias')
            ->setSerious(false)
            ->setPosition(0);
        $behavior  = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Perturbación del normal desarrollo de las actividades')
            ->setPosition(0)
            ->setActive(true);

        $centre->setActiveAcademicYear($year);
        $group->addStudent($student);
        $this->persist($teacher, $centre, $year, $course, $group, $student, $category, $behavior);

        return [$teacher, $centre, $group, $student, $behavior];
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

        $this->persist($report);

        return $report;
    }

    private function makeSanction(Student $student, Group $group, Teacher $registeredBy): Sanction
    {
        $academicYear = $group->getCourse()->getAcademicYear();

        $sanction = (new Sanction())
            ->setAcademicYear($academicYear)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($registeredBy)
            ->setDetails('Detalles de prueba.')
            ->setNoMeasureApplied(false);

        $this->persist($sanction);

        return $sanction;
    }
}
