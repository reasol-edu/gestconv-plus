<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class TutorshipControllerTest extends ControllerTestCase
{
    private static int $scenarioCounter = 0;

    public function testIndexRedirectsAnonymousUser(): void
    {
        $this->client->request('GET', '/mi-tutoria');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testIndexDeniesTeacherWhoDoesNotTutorAnyGroup(): void
    {
        [$centre, , $group, ] = $this->makeScenario();
        $plain = $this->makeTeacher('tutorship.plain.1');
        $group->addTeacher($plain, 'Matemáticas');
        $this->persist($plain);

        $this->loginAs($plain, $centre);
        $this->client->request('GET', '/mi-tutoria');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexIsAccessibleToGroupTutor(): void
    {
        [$centre, , $group, $student] = $this->makeScenario();
        $tutor = $this->makeTeacher('tutorship.tutor.2');
        $group->addTutor($tutor);
        $this->persist($tutor);

        $this->loginAs($tutor, $centre);
        $crawler = $this->client->request('GET', '/mi-tutoria');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mi tutoría', $crawler->filter('h1')->text());
        self::assertStringContainsString('García, Ana', (string) $this->client->getResponse()->getContent());
    }

    public function testIndexPreFiltersByGroupIdFromQueryParam(): void
    {
        [$centre, $year, $group, ] = $this->makeScenario();
        $course2  = (new Course())->setName('DAM')->setAcademicYear($year);
        $group2   = (new Group())->setName('1ºB')->setCourse($course2);
        $student2 = (new Student(new PersonName('Luis', 'Martín')))->setStudentId('NIE-' . uniqid('', false));
        $group2->addStudent($student2);
        $tutor = $this->makeTeacher('tutorship.groupfilter.4');
        $group->addTutor($tutor);
        $group2->addTutor($tutor);
        $this->persist($course2, $group2, $student2, $tutor);

        $this->loginAs($tutor, $centre);
        $this->client->request('GET', '/mi-tutoria?groupId=' . $group->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('García, Ana', $content);
        self::assertStringNotContainsString('Martín, Luis', $content);
    }

    public function testIndexDeniesWhenCentreHasNoActiveYear(): void
    {
        $suffix  = (string) ++self::$scenarioCounter;
        $centre  = (new EducationalCentre())->setCode('7' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Sin Curso')->setCity('Sevilla');
        $tutor   = $this->makeTeacher('tutorship.noyear.3');
        $this->persist($centre, $tutor);

        $this->loginAs($tutor, $centre);
        $this->client->request('GET', '/mi-tutoria');

        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: EducationalCentre, 1: AcademicYear, 2: Group, 3: Student}
     */
    private function makeScenario(): array
    {
        $suffix  = (string) ++self::$scenarioCounter;
        $centre  = (new EducationalCentre())->setCode('7' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . uniqid('', false));

        $centre->setActiveAcademicYear($year);
        $group->addStudent($student);
        $this->persist($centre, $year, $course, $group, $student);

        return [$centre, $year, $group, $student];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }
}
