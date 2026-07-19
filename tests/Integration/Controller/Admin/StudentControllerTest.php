<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class StudentControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToAdmin(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $this->loginAs($admin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/estudiantes');

        self::assertResponseIsSuccessful();
    }

    public function testIndexIsAccessibleToEquipoDirectivo(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $directivo = $this->makeTeacher('directivo.1');
        $this->persist($admin, $centre, $year, $directivo);
        $centre->addAdmin($directivo);
        $this->flush();
        $this->loginAs($directivo);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/estudiantes');

        self::assertResponseIsSuccessful();
    }

    public function testIndexDeniesNonAdmin(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $teacher = $this->makeTeacher('teacher.1');
        $this->persist($admin, $centre, $year, $teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/estudiantes');

        self::assertResponseStatusCodeSame(403);
    }

    // ── new ───────────────────────────────────────────────────────────────────

    public function testNewGetRendersForm(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/estudiantes/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testNewPostCreatesStudentAndRedirects(): void
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
        self::assertStringContainsString('/estudiantes', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testNewPostWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/nuevo', [
            '_token'    => 'token-invalido',
            'firstName' => 'Ana',
            'lastName'  => 'Martinez',
            'studentId' => '2024-001',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewPostWithEmptyFirstNameRendersFormAgain(): void
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
            'firstName' => '',
            'lastName'  => 'Martinez',
            'studentId' => '2024-001',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testNewPostWithDuplicateStudentIdRendersFormAgain(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $student = $this->makeStudent('2024-001');
        $this->persist($admin, $centre, $year, $student);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/nuevo');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/nuevo', [
            '_token'    => $token,
            'firstName' => 'Otro',
            'lastName'  => 'Alumno',
            'studentId' => '2024-001',
        ]);

        self::assertResponseIsSuccessful();
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditGetRendersForm(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $student = $this->makeStudent('2024-001');
        $this->persist($admin, $centre, $year, $student);
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $studentId = $student->getId()->toRfc4122();

        $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/' . $studentId . '/editar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditGetShowsLinkToStudentProfile(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $student = $this->makeStudent('2024-001');
        $this->persist($admin, $centre, $year, $student);
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $studentId = $student->getId()->toRfc4122();

        $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/' . $studentId . '/editar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/alumnado/' . $studentId . '"]');
    }

    public function testEditPostSavesChangesAndRedirects(): void
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
        $updated = $this->em->find(Student::class, $student->getId());
        self::assertSame('Modificado', $updated->getName()->getFirstName());
    }

    public function testEditPostWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $student = $this->makeStudent('2024-001');
        $this->persist($admin, $centre, $year, $student);
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $studentId = $student->getId()->toRfc4122();
        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/' . $studentId . '/editar', [
            '_token'    => 'token-invalido',
            'firstName' => 'Hack',
            'lastName'  => 'Attack',
            'studentId' => '2024-001',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditIsDeniedForStudentOfAnotherCentre(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        [$otherAdmin, $otherCentre, $otherYear] = $this->makeCentreWithYear();
        $otherAdmin->setUsername('admin.other');
        $otherCentre->setCode('41000002');
        [$course, $group] = $this->makeCourseWithGroup($otherYear, 'DAM1');
        $student = $this->makeStudent('2024-001')->addGroup($group);
        $this->persist($admin, $centre, $year, $otherAdmin, $otherCentre, $otherYear, $course, $group, $student);
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $studentId = $student->getId()->toRfc4122();

        $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/' . $studentId . '/editar');

        self::assertResponseStatusCodeSame(404);
    }

    // ── import ────────────────────────────────────────────────────────────────

    public function testImportGetRendersForm(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/estudiantes/importar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testImportStep1WithValidCsvRendersPreview(): void
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
            '"","2024-001","Martinez","Lopez","Ana","DAW1A","1r DAW"',
        ]);
        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, $csv);
        $file = new UploadedFile($tmpFile, 'students.csv', 'text/csv', null, true);

        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            '_token' => $token,
        ], ['csv' => $file]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="import_confirmed"]');
        self::assertSelectorExists('[name="import_id"]');

        @unlink($tmpFile);
    }

    public function testImportStep2ConfirmImportsStudentsAndRedirects(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        [$course, $group] = $this->makeCourseWithGroup($year, 'DAW1A', '1r DAW');
        $this->persist($course, $group);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $csv = implode("\n", [
            '"Estado Matrícula","Nº Id. Escolar","Primer apellido","Segundo apellido","Nombre","Unidad","Curso"',
            '"","2024-001","Martinez","Lopez","Ana","DAW1A","1r DAW"',
        ]);
        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, $csv);
        $file = new UploadedFile($tmpFile, 'students.csv', 'text/csv', null, true);

        // Step 1: upload → preview
        $previewCrawler = $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            '_token' => $token,
        ], ['csv' => $file]);
        @unlink($tmpFile);

        self::assertResponseIsSuccessful();
        $importId     = $previewCrawler->filter('[name="import_id"]')->first()->attr('value');
        $confirmToken = $previewCrawler->filter('[name="_token"]')->first()->attr('value');

        // Step 2: confirm → import
        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            'import_confirmed' => '1',
            'import_id'        => $importId,
            '_token'           => $confirmToken,
            'groups'           => [$group->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/estudiantes', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        $created = $this->em->getRepository(Student::class)->findOneBy(['studentId' => '2024-001']);
        self::assertNotNull($created);
        self::assertSame('Ana', $created->getName()->getFirstName());
    }

    public function testImportStep2WithExpiredSessionRedirectsWithError(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();

        // Do step 1 with a valid CSV to obtain a real confirm CSRF token from the preview page
        $crawler = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/importar');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $csv     = implode("\n", [
            '"Estado Matrícula","Nº Id. Escolar","Primer apellido","Segundo apellido","Nombre","Unidad","Curso"',
            '"","2024-999","Perez","","Luis","DAW1A","1r DAW"',
        ]);
        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, $csv);
        $file = new UploadedFile($tmpFile, 'students.csv', 'text/csv', null, true);

        $previewCrawler = $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            '_token' => $token,
        ], ['csv' => $file]);
        @unlink($tmpFile);

        self::assertResponseIsSuccessful();
        $confirmToken = $previewCrawler->filter('[name="_token"]')->first()->attr('value');

        // Step 2 with a tampered import_id (session has the real one, this won't match)
        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            'import_confirmed' => '1',
            'import_id'        => 'non-existent-uuid-that-wont-match',
            '_token'           => $confirmToken,
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/importar', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testImportStep2WithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            'import_confirmed' => '1',
            'import_id'        => 'some-uuid',
            '_token'           => 'token-invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportPostWithNoFileRendersFormAgain(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            '_token' => $token,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testImportPostWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            '_token' => 'token-invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportStep1ShowsGroupCheckboxesForKnownGroups(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $centre->setActiveAcademicYear($year);
        [$course, $group] = $this->makeCourseWithGroup($year, 'DAW1A', '1r DAW');
        $this->persist($admin, $centre, $year, $course, $group);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $csv     = implode("\n", [
            '"Estado Matrícula","Nº Id. Escolar","Primer apellido","Segundo apellido","Nombre","Unidad","Curso"',
            '"","2024-001","Martinez","Lopez","Ana","DAW1A","1r DAW"',
            '"","2024-002","Sanchez","","Pedro","DAW1A","1r DAW"',
        ]);
        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, $csv);
        $file = new UploadedFile($tmpFile, 'students.csv', 'text/csv', null, true);

        $previewCrawler = $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            '_token' => $token,
        ], ['csv' => $file]);
        @unlink($tmpFile);

        self::assertResponseIsSuccessful();
        $checkbox = $previewCrawler->filter('input[name="groups[]"][value="' . $group->getId()->toRfc4122() . '"]');
        self::assertCount(1, $checkbox);
        self::assertNotNull($checkbox->attr('checked'), 'El checkbox del grupo debe estar marcado por defecto');
    }

    public function testImportStep2RespectsGroupFilter(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $centre->setActiveAcademicYear($year);
        $course = (new Course())->setName('1r DAW')->setAcademicYear($year);
        $groupA = (new Group())->setName('DAW1A')->setCourse($course);
        $groupB = (new Group())->setName('DAW1B')->setCourse($course);
        $this->persist($admin, $centre, $year, $course, $groupA, $groupB);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $csv     = implode("\n", [
            '"Estado Matrícula","Nº Id. Escolar","Primer apellido","Segundo apellido","Nombre","Unidad","Curso"',
            '"","2024-A01","Garcia","","Ana","DAW1A","1r DAW"',
            '"","2024-B01","Lopez","","Pedro","DAW1B","1r DAW"',
        ]);
        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, $csv);
        $file = new UploadedFile($tmpFile, 'students.csv', 'text/csv', null, true);

        // Step 1: upload → preview
        $previewCrawler = $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            '_token' => $token,
        ], ['csv' => $file]);
        @unlink($tmpFile);

        self::assertResponseIsSuccessful();
        $importId     = $previewCrawler->filter('[name="import_id"]')->first()->attr('value');
        $confirmToken = $previewCrawler->filter('[name="_token"]')->first()->attr('value');

        // Step 2: confirmar solo con el grupo DAW1A seleccionado
        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            'import_confirmed' => '1',
            'import_id'        => $importId,
            '_token'           => $confirmToken,
            'groups'           => [$groupA->getId()->toRfc4122()],
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        self::assertNotNull(
            $this->em->getRepository(Student::class)->findOneBy(['studentId' => '2024-A01']),
            'El estudiante de DAW1A debe haberse importado'
        );
        self::assertNull(
            $this->em->getRepository(Student::class)->findOneBy(['studentId' => '2024-B01']),
            'El estudiante de DAW1B no debe haberse importado'
        );
    }

    public function testImportCreatesOneGroupWhenSameNameAppearsInMultipleNewCourses(): void
    {
        // Group names are unique: "A" appearing in "1º ESO" and "2º ESO" is detected as a
        // conflict (same name, multiple courses). The preview asks the user to choose one course.
        // After resolution, exactly one Group "A" must exist and both students belong to it.
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $centre->setActiveAcademicYear($year);
        $this->persist($admin, $centre, $year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/estudiantes/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $csv = implode("\n", [
            '"Estado Matrícula","Nº Id. Escolar","Primer apellido","Segundo apellido","Nombre","Unidad","Curso"',
            '"","2024-ESO1","Garcia","","Ana","A","1º ESO"',
            '"","2024-ESO2","Lopez","","Pedro","A","2º ESO"',
        ]);
        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, $csv);
        $file = new UploadedFile($tmpFile, 'students.csv', 'text/csv', null, true);

        // Step 1: upload → preview (group "A" shows as a conflict group)
        $previewCrawler = $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            '_token' => $token,
        ], ['csv' => $file]);
        @unlink($tmpFile);

        self::assertResponseIsSuccessful();
        $importId     = $previewCrawler->filter('[name="import_id"]')->first()->attr('value');
        $confirmToken = $previewCrawler->filter('[name="_token"]')->first()->attr('value');

        // Step 2: resolve conflict — user assigns group "A" to course "1º ESO"
        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/importar', [
            'import_confirmed'       => '1',
            'import_id'              => $importId,
            '_token'                 => $confirmToken,
            'conflict_group_names'   => ['A'],
            'conflict_group_courses' => ['1º ESO'],
        ]);

        self::assertResponseRedirects();

        $this->em->clear();

        // Exactly one group named "A" must exist — no duplicates
        $groups = $this->em->getRepository(Group::class)->findBy(['name' => 'A']);
        self::assertCount(1, $groups, 'Solo debe existir un grupo llamado "A", no uno por curso');

        $student1 = $this->em->getRepository(Student::class)->findOneBy(['studentId' => '2024-ESO1']);
        $student2 = $this->em->getRepository(Student::class)->findOneBy(['studentId' => '2024-ESO2']);

        self::assertNotNull($student1, 'El alumno de 1º ESO debe haberse importado');
        self::assertNotNull($student2, 'El alumno de 2º ESO debe haberse importado');

        // Both students must be in the same single group "A"
        $groupId1 = $student1->getGroups()->toArray()[0]->getId()->toRfc4122();
        $groupId2 = $student2->getGroups()->toArray()[0]->getId()->toRfc4122();
        self::assertSame($groupId1, $groupId2, 'Ambos alumnos deben estar en el mismo grupo "A"');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteStudentDeletesEntityAndRedirects(): void
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
        self::assertStringContainsString('/estudiantes', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        self::assertNull($this->em->find(Student::class, $student->getId()));
    }

    public function testDeleteWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $student = $this->makeStudent('2024-001');
        $this->persist($admin, $centre, $year, $student);
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $studentId = $student->getId()->toRfc4122();
        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/' . $studentId . '/eliminar', [
            '_token' => 'token-invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteIsDeniedForStudentOfAnotherCentre(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        [$otherAdmin, $otherCentre, $otherYear] = $this->makeCentreWithYear();
        $otherAdmin->setUsername('admin.other');
        $otherCentre->setCode('41000002');
        [$course, $group] = $this->makeCourseWithGroup($otherYear, 'DAM1');
        $student = $this->makeStudent('2024-001')->addGroup($group);
        $this->persist($admin, $centre, $year, $otherAdmin, $otherCentre, $otherYear, $course, $group, $student);
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $studentId = $student->getId()->toRfc4122();

        // El control de pertenencia al centro ocurre antes de validar el CSRF,
        // así que un token cualquiera basta para probar el 404.
        $this->client->request('POST', '/centro/' . $centreId . '/estudiantes/' . $studentId . '/eliminar', [
            '_token' => 'irrelevant',
        ]);

        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        self::assertNotNull($this->em->find(Student::class, $student->getId()));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear} */
    private function makeCentreWithYear(): array
    {
        $admin  = (new Teacher(new PersonName('Admin', 'User')))->setUsername('admin.1')->setAdmin(true);
        $centre = (new EducationalCentre())->setCode('41000001')->setName('IES Test')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);

        return [$admin, $centre, $year];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function makeStudent(string $studentId): Student
    {
        return (new Student(new PersonName('Test', 'Student')))->setStudentId($studentId);
    }

    /** @return array{0: Course, 1: Group} */
    private function makeCourseWithGroup(AcademicYear $year, string $groupName, string $courseName = '1r DAW'): array
    {
        $course = (new Course())->setName($courseName)->setAcademicYear($year);
        $group  = (new Group())->setName($groupName)->setCourse($course);

        return [$course, $group];
    }
}
