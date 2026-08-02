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

class SearchControllerTest extends ControllerTestCase
{
    public function testSearchRedirectsAnonymousUser(): void
    {
        $this->client->request('GET', '/buscar?q=test');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testSearchReturnsEmptyGroupsWhenQueryTooShort(): void
    {
        [$centre, $admin] = $this->makeChain('41000070', 'search.admin.70');
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/buscar?q=a');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['groups' => []], $data);
    }

    public function testSearchReturnsStudentForCentreAdmin(): void
    {
        [$centre, $admin, $course] = $this->makeChain('41000076', 'search.admin.76');

        $group   = (new Group())->setCourse($course)->setName('1DAW-A');
        $student = new Student(new PersonName('Martina', 'Buscable'));
        $student->setStudentId('NIE-76A');
        $group->addStudent($student);
        $this->persist($group, $student);

        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/buscar?q=Buscable');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('students_other', $data['groups']);
        self::assertStringContainsString('Buscable', $data['groups']['students_other'][0]['label']);
        self::assertSame('1DAW-A', $data['groups']['students_other'][0]['sublabel']);
    }

    public function testSearchReturnsStudentForGlobalAdmin(): void
    {
        [$centre, , $course] = $this->makeChain('41000077', 'search.cadmin.77');

        $group   = (new Group())->setCourse($course)->setName('1DAW-A');
        $student = new Student(new PersonName('Martina', 'GlobalAdmin'));
        $student->setStudentId('NIE-77A');
        $group->addStudent($student);
        $this->persist($group, $student);

        $globalAdmin = (new Teacher(new PersonName('Global', 'Admin')))->setUsername('global.admin.77');
        $globalAdmin->setPassword('x');
        $globalAdmin->setAdmin(true);
        $this->persist($globalAdmin);

        $this->loginAs($globalAdmin, $centre);

        $this->client->request('GET', '/buscar?q=GlobalAdmin');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('students_other', $data['groups']);
        self::assertStringContainsString('GlobalAdmin', $data['groups']['students_other'][0]['label']);
    }

    public function testSearchReturnsStudentInOwnGroupForNonAdminTeacher(): void
    {
        [$centre, , $course] = $this->makeChain('41000078', 'search.admin.78');

        $group   = (new Group())->setCourse($course)->setName('1DAW-A');
        $student = new Student(new PersonName('Laura', 'Visible'));
        $student->setStudentId('NIE-78A');
        $group->addStudent($student);

        $teacher = (new Teacher(new PersonName('Profe', 'Normal')))->setUsername('search.teacher.78');
        $teacher->setPassword('x');
        $group->addTeacher($teacher, 'Matemáticas');

        $this->persist($group, $student, $teacher);

        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/buscar?q=Visible');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('students_taught', $data['groups']);
        self::assertStringContainsString('Visible', $data['groups']['students_taught'][0]['label']);
        self::assertSame('1DAW-A', $data['groups']['students_taught'][0]['sublabel']);
    }

    public function testSearchSeparatesTaughtAndOtherStudentsForNonAdminTeacher(): void
    {
        [$centre, , $course] = $this->makeChain('41000079', 'search.admin.79');

        $ownGroup   = (new Group())->setCourse($course)->setName('1DAW-A');
        $otherGroup = (new Group())->setCourse($course)->setName('1DAW-B');

        $ownStudent   = new Student(new PersonName('Ana', 'MiGrupo'));
        $ownStudent->setStudentId('NIE-79A');
        $ownGroup->addStudent($ownStudent);

        $otherStudent = new Student(new PersonName('Luis', 'OtroGrupo'));
        $otherStudent->setStudentId('NIE-79B');
        $otherGroup->addStudent($otherStudent);

        $teacher = (new Teacher(new PersonName('Profe', 'Normal')))->setUsername('search.teacher.79');
        $teacher->setPassword('x');
        $ownGroup->addTeacher($teacher, 'Matemáticas');

        $this->persist($ownGroup, $otherGroup, $ownStudent, $otherStudent, $teacher);

        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/buscar?q=Grupo');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $taughtLabels = array_column($data['groups']['students_taught'] ?? [], 'label');
        $otherLabels  = array_column($data['groups']['students_other'] ?? [], 'label');
        self::assertCount(1, $taughtLabels);
        self::assertStringContainsString('MiGrupo', $taughtLabels[0]);
        self::assertCount(1, $otherLabels);
        self::assertStringContainsString('OtroGrupo', $otherLabels[0]);
        self::assertSame('1DAW-B', $data['groups']['students_other'][0]['sublabel']);
    }

    public function testSearchPutsTutoredStudentInTaughtGroupEvenWithoutTeachingSubject(): void
    {
        [$centre, , $course] = $this->makeChain('41000080', 'search.admin.80');

        $group   = (new Group())->setCourse($course)->setName('1DAW-A');
        $student = new Student(new PersonName('Elena', 'Tutorizada'));
        $student->setStudentId('NIE-80A');
        $group->addStudent($student);

        $tutor = (new Teacher(new PersonName('Tutor', 'Sin materia')))->setUsername('search.tutor.80');
        $tutor->setPassword('x');
        $group->addTutor($tutor);

        $this->persist($group, $student, $tutor);

        $this->loginAs($tutor, $centre);

        $this->client->request('GET', '/buscar?q=Tutorizada');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('students_taught', $data['groups']);
        self::assertStringContainsString('Tutorizada', $data['groups']['students_taught'][0]['label']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{EducationalCentre, Teacher, Course}
     */
    private function makeChain(string $code, string $username): array
    {
        $centre = (new EducationalCentre())->setCode($code)->setName('IES ' . $code)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course = (new Course())->setName('DAW')->setAcademicYear($year);
        $admin  = (new Teacher(new PersonName('Admin', 'Centro')))->setUsername($username);
        $this->persist($centre, $year, $course, $admin);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        $this->flush();

        return [$centre, $admin, $course];
    }
}
