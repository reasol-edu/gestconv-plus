<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class StudentActivityLogTest extends ControllerTestCase
{
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

    public function testCreatingStudentLogsStudentCreated(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/nuevo');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/nuevo', [
            '_token'    => $token,
            'firstName' => 'Ana',
            'lastName'  => 'Martinez',
            'studentId' => '2024-001',
            'details'   => '',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('student.created', $logs[0]->getActionType());
        self::assertSame('2024-001', $logs[0]->getData()['studentId'] ?? null);
    }

    public function testEditingStudentLogsStudentUpdatedWithDiff(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $student = $this->makeStudent('2024-001');
        $this->persist($admin, $centre, $year, $student);
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $studentId = $student->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/' . $studentId . '/editar');
        $token     = $crawler->filter('form')->first()->filter('[name="_token"]')->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/' . $studentId . '/editar', [
            '_token'    => $token,
            'firstName' => 'Modificado',
            'lastName'  => 'Apellido',
            'studentId' => '2024-001',
            'details'   => '',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('student.updated', $logs[0]->getActionType());
        $changes = $logs[0]->getData()['changes'] ?? [];
        self::assertSame('Test', $changes['name']['before']['firstName'] ?? null);
        self::assertSame('Modificado', $changes['name']['after']['firstName'] ?? null);
    }

    public function testDeletingStudentLogsStudentDeleted(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $student = $this->makeStudent('2024-001');
        $this->persist($admin, $centre, $year, $student);
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $studentId = $student->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/' . $studentId . '/editar');
        $token     = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/' . $studentId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('student.deleted', $logs[0]->getActionType());
        self::assertSame($studentId, $logs[0]->getData()['entityId'] ?? null);
        self::assertSame('2024-001', $logs[0]->getData()['studentId'] ?? null);
    }

    public function testImportingStudentsLogsStudentImportedWithCounters(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $csv = implode("\n", [
            '"Estado Matrícula","Nº Id. Escolar","Primer apellido","Segundo apellido","Nombre","Unidad","Curso"',
            '"","2024-001","Martinez","Lopez","Ana","DAW1A","DAW"',
        ]);
        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, $csv);
        $file = new UploadedFile($tmpFile, 'students.csv', 'text/csv', null, true);

        $previewCrawler = $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            '_token' => $token,
        ], ['csv' => $file]);
        @unlink($tmpFile);

        self::assertResponseIsSuccessful();
        $importId     = $previewCrawler->filter('[name="import_id"]')->first()->attr('value');
        $confirmToken = $previewCrawler->filter('[name="_token"]')->first()->attr('value');
        $newGroups    = $previewCrawler->filter('[name="new_groups[]"]')->each(
            static fn ($node) => $node->attr('value'),
        );

        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            'import_confirmed' => '1',
            'import_id'        => $importId,
            '_token'           => $confirmToken,
            'new_groups'       => $newGroups,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $logs = $this->em->getRepository(ActivityLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame('student.imported', $logs[0]->getActionType());
        self::assertSame(1, $logs[0]->getData()['created'] ?? null);
        self::assertSame(0, $logs[0]->getData()['updated'] ?? null);
        self::assertSame(0, $logs[0]->getData()['skipped'] ?? null);
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear} */
    private function makeCentreWithYear(): array
    {
        $admin  = (new Teacher(new PersonName('Admin', 'User')))->setUsername('admin.' . uniqid('', false))->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('9' . substr(uniqid('', false), 0, 7))->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);

        return [$admin, $centre, $year];
    }

    private function makeStudent(string $studentId): Student
    {
        return (new Student(new PersonName('Test', 'Student')))->setStudentId($studentId);
    }
}
