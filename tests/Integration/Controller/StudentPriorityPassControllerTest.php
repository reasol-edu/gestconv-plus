<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\StudentPriorityPass;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class StudentPriorityPassControllerTest extends ControllerTestCase
{
    public function testCentreAdminGrantsAndUpdatesPass(): void
    {
        [$cadmin, $centre, $student] = $this->makeScenario();
        $this->loginAs($cadmin, $centre);

        $token = $this->tokenFor($student);
        $url   = '/alumnado/' . $student->getId()->toRfc4122() . '/pase-prioritario';

        $this->client->request('POST', $url, ['_token' => $token, 'description' => 'Movilidad reducida']);
        self::assertResponseRedirects('/alumnado/' . $student->getId()->toRfc4122());

        $this->em->clear();
        $passes = $this->em->getRepository(StudentPriorityPass::class)->findAll();
        self::assertCount(1, $passes);
        self::assertSame('Movilidad reducida', $passes[0]->getDescription());
        self::assertNotNull($passes[0]->getGrantedBy());

        // Una segunda llamada edita el mismo pase: la restricción única (alumno, curso) se respeta.
        $this->client->request('POST', $url, ['_token' => $this->tokenFor($student), 'description' => 'Ascensor']);
        $this->em->clear();
        $passes = $this->em->getRepository(StudentPriorityPass::class)->findAll();
        self::assertCount(1, $passes);
        self::assertSame('Ascensor', $passes[0]->getDescription());
    }

    public function testEmptyDescriptionIsRejected(): void
    {
        [$cadmin, $centre, $student] = $this->makeScenario();
        $this->loginAs($cadmin, $centre);

        $this->client->request('POST', '/alumnado/' . $student->getId()->toRfc4122() . '/pase-prioritario', [
            '_token'      => $this->tokenFor($student),
            'description' => '   ',
        ]);

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(StudentPriorityPass::class)->findAll());
    }

    public function testRevokeRemovesPass(): void
    {
        [$cadmin, $centre, $student, $year] = $this->makeScenario();
        $this->persist((new StudentPriorityPass())->setStudent($student)->setAcademicYear($year)->setDescription('x'));
        $this->loginAs($cadmin, $centre);

        $this->client->request('POST', '/alumnado/' . $student->getId()->toRfc4122() . '/pase-prioritario/eliminar', [
            '_token' => $this->tokenFor($student),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(StudentPriorityPass::class)->findAll());
    }

    public function testRegularTeacherCannotManagePassAndSheetHidesForm(): void
    {
        [, $centre, $student] = $this->makeScenario();
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('plain.pass.' . uniqid('', false));
        $this->persist($teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('POST', '/alumnado/' . $student->getId()->toRfc4122() . '/pase-prioritario', [
            '_token'      => 'whatever',
            'description' => 'Intento',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/alumnado/' . $student->getId()->toRfc4122());
        self::assertSelectorNotExists('textarea[name="description"]');
    }

    public function testSheetShowsTheFormToCentreAdmin(): void
    {
        [$cadmin, $centre, $student] = $this->makeScenario();
        $this->loginAs($cadmin, $centre);

        $this->client->request('GET', '/alumnado/' . $student->getId()->toRfc4122());

        self::assertSelectorExists('textarea[name="description"]');
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: Student, 3: AcademicYear} */
    private function makeScenario(): array
    {
        $suffix  = uniqid('', false);
        $cadmin  = (new Teacher(new PersonName('Admin', 'Centre')))->setUsername('cadmin.pass.' . $suffix);
        $centre  = (new EducationalCentre())->setCode('7' . substr($suffix, 0, 7))->setName('IES Test')->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . $suffix);
        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);
        $student->addGroup($group);
        $this->persist($cadmin, $centre, $year, $course, $group, $student);

        return [$cadmin, $centre, $student, $year];
    }

    private function tokenFor(Student $student): string
    {
        $crawler = $this->client->request('GET', '/alumnado/' . $student->getId()->toRfc4122());

        return $crawler->filter('form[action$="pase-prioritario"] [name="_token"]')->first()->attr('value') ?? '';
    }
}
