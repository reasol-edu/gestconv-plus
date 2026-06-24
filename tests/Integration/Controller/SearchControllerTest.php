<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
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
        [$centre, $admin, $prog] = $this->makeChain('41000076', 'search.admin.76');

        $progYear = (new ProgrammeYear())->setName('1º DAW')->setProgramme($prog);
        $group    = (new Group())->setProgrammeYear($progYear)->setName('1DAW-A');
        $student  = new Student(new PersonName('Martina', 'Buscable'));
        $student->setStudentId('NIE-76A');
        $group->addStudent($student);
        $this->persist($progYear, $group, $student);

        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/buscar?q=Buscable');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('students', $data['groups']);
        self::assertStringContainsString('Buscable', $data['groups']['students'][0]['label']);
    }

    public function testSearchReturnsStudentForGlobalAdmin(): void
    {
        [$centre, , $prog] = $this->makeChain('41000077', 'search.cadmin.77');

        $progYear = (new ProgrammeYear())->setName('1º DAW')->setProgramme($prog);
        $group    = (new Group())->setProgrammeYear($progYear)->setName('1DAW-A');
        $student  = new Student(new PersonName('Martina', 'GlobalAdmin'));
        $student->setStudentId('NIE-77A');
        $group->addStudent($student);
        $this->persist($progYear, $group, $student);

        $globalAdmin = (new Teacher(new PersonName('Global', 'Admin')))->setUsername('global.admin.77');
        $globalAdmin->setPassword('x');
        $globalAdmin->setAdmin(true);
        $this->persist($globalAdmin);

        $this->loginAs($globalAdmin, $centre);

        $this->client->request('GET', '/buscar?q=GlobalAdmin');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('students', $data['groups']);
        self::assertStringContainsString('GlobalAdmin', $data['groups']['students'][0]['label']);
    }

    public function testSearchReturnsStudentInOwnGroupForNonAdminTeacher(): void
    {
        [$centre, , $prog] = $this->makeChain('41000078', 'search.admin.78');

        $progYear = (new ProgrammeYear())->setName('1º DAW')->setProgramme($prog);
        $group    = (new Group())->setProgrammeYear($progYear)->setName('1DAW-A');
        $student  = new Student(new PersonName('Laura', 'Visible'));
        $student->setStudentId('NIE-78A');
        $group->addStudent($student);

        $teacher = (new Teacher(new PersonName('Profe', 'Normal')))->setUsername('search.teacher.78');
        $teacher->setPassword('x');
        $group->addTeacher($teacher);

        $this->persist($progYear, $group, $student, $teacher);

        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/buscar?q=Visible');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('students', $data['groups']);
        self::assertStringContainsString('Visible', $data['groups']['students'][0]['label']);
    }

    public function testSearchDoesNotReturnStudentFromOtherGroupForNonAdminTeacher(): void
    {
        [$centre, , $prog] = $this->makeChain('41000079', 'search.admin.79');

        $progYear  = (new ProgrammeYear())->setName('1º DAW')->setProgramme($prog);
        $ownGroup  = (new Group())->setProgrammeYear($progYear)->setName('1DAW-A');
        $otherGroup = (new Group())->setProgrammeYear($progYear)->setName('1DAW-B');

        $ownStudent   = new Student(new PersonName('Ana', 'MiGrupo'));
        $ownStudent->setStudentId('NIE-79A');
        $ownGroup->addStudent($ownStudent);

        $otherStudent = new Student(new PersonName('Luis', 'OtroGrupo'));
        $otherStudent->setStudentId('NIE-79B');
        $otherGroup->addStudent($otherStudent);

        $teacher = (new Teacher(new PersonName('Profe', 'Normal')))->setUsername('search.teacher.79');
        $teacher->setPassword('x');
        $ownGroup->addTeacher($teacher);

        $this->persist($progYear, $ownGroup, $otherGroup, $ownStudent, $otherStudent, $teacher);

        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/buscar?q=Grupo');

        self::assertResponseIsSuccessful();
        $data    = json_decode((string) $this->client->getResponse()->getContent(), true);
        $labels  = array_column($data['groups']['students'] ?? [], 'label');
        self::assertCount(1, $labels);
        self::assertStringContainsString('MiGrupo', $labels[0]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{EducationalCentre, Teacher, Programme}
     */
    private function makeChain(string $code, string $username): array
    {
        $centre = (new EducationalCentre())->setCode($code)->setName('IES ' . $code)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $prog   = (new Programme())->setName('DAW')->setAcademicYear($year);
        $admin  = (new Teacher(new PersonName('Admin', 'Centro')))->setUsername($username);
        $this->persist($centre, $year, $prog, $admin);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        $this->flush();

        return [$centre, $admin, $prog];
    }
}
