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
