<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class CentreTeacherControllerTest extends ControllerTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToAdmin(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $this->loginAs($admin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/docentes-curso');

        self::assertResponseIsSuccessful();
    }

    public function testIndexShowsEmailAndPendingEmailOfTeachers(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $with    = $this->makeTeacher('con.correo')->setEmail('con.correo@iesejemplo.es');
        $pending = $this->makeTeacher('cambiando')->setPendingEmail('nuevo@iesejemplo.es');
        $this->persist($admin, $centre, $year, $with, $pending);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($with);
        $year->addTeacher($pending);
        $this->flush();
        $this->loginAs($admin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/docentes-curso');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'con.correo@iesejemplo.es');
        self::assertSelectorTextContains('table', 'Pendiente de verificar: nuevo@iesejemplo.es');
        // La columna es visible siempre (la tabla se adapta a tarjetas en móvil con la etiqueta de cada celda).
        self::assertSelectorExists('table.table-cards td[data-label="Correo"]');
        self::assertSelectorNotExists('td.hidden');
    }

    public function testIndexIsAccessibleToEquipoDirectivo(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $directivo = $this->makeTeacher('directivo.1');
        $this->persist($admin, $centre, $year, $directivo);
        $centre->addAdmin($directivo);
        $this->flush();
        $this->loginAs($directivo);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/docentes-curso');

        self::assertResponseIsSuccessful();
    }

    public function testIndexDeniesNonAdmin(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $teacher = $this->makeTeacher('teacher.1');
        $this->persist($admin, $centre, $year, $teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/docentes-curso');

        self::assertResponseStatusCodeSame(403);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddKnownUsernameAddsTeacherToYearAndRedirects(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $teacher = $this->makeTeacher('teacher.1');
        $this->persist($admin, $centre, $year, $teacher);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/a%C3%B1adir', [
            '_token'   => $token,
            'username' => 'teacher.1',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/docentes-curso', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAddKnownTeacherReplacingAnotherCopiesProfilesTutorshipsAndSubjects(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $old = $this->makeTeacher('sustituido.add');
        $new = $this->makeTeacher('sustituto.add');
        $course = (new \App\Entity\Course())->setName('1º ESO')->setAcademicYear($year);
        $group  = (new \App\Entity\Group())->setName('1ºB')->setCourse($course);
        $this->persist($admin, $centre, $year, $old, $new, $course, $group);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($old);
        $group->addTutor($old);
        $group->addTeacher($old, 'Lengua');
        $centre->addCounselor($old);
        $centre->addActivityCoordinator($old);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso');
        self::assertSelectorExists('select[name="replaces"]');
        // El listado ya trae formularios con su propio token (retirar docente): se toma el del formulario de añadir.
        $token = $crawler->filterXPath('//form[.//input[@id="teacher-username"]]//input[@name="_token"]')->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/a%C3%B1adir', [
            '_token'   => $token,
            'username' => 'sustituto.add',
            'replaces' => $old->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $newAgain = $this->em->getRepository(\App\Entity\Teacher::class)->findOneBy(['username' => 'sustituto.add']);
        $centreNow = $this->em->find(\App\Entity\EducationalCentre::class, $centre->getId());
        $groupNow  = $this->em->find(\App\Entity\Group::class, $group->getId());
        self::assertTrue($centreNow->getCounselors()->contains($newAgain));
        self::assertTrue($centreNow->getActivityCoordinators()->contains($newAgain));
        self::assertTrue($groupNow->getTutors()->contains($newAgain));
        self::assertTrue($groupNow->hasTeacherSubject($newAgain, 'Lengua'));
    }

    public function testReplaceActionCopiesEverythingFromAnotherTeacherOfTheYear(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $old = $this->makeTeacher('sustituido.act');
        $new = $this->makeTeacher('sustituto.act');
        $course = (new \App\Entity\Course())->setName('1º ESO')->setAcademicYear($year);
        $group  = (new \App\Entity\Group())->setName('1ºC')->setCourse($course);
        $slot   = (new \App\Entity\TimeSlot())->setName('3ª')->setDayOfWeek(1)->setAcademicYear($year)
            ->setStartTime(new \DateTimeImmutable('10:00'))->setEndTime(new \DateTimeImmutable('11:00'));
        $this->persist($admin, $centre, $year, $old, $new, $course, $group, $slot);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($old);
        $year->addTeacher($new);
        $group->addTutor($old);
        $group->addTeacher($old, 'Biología');
        $slot->addGuard($old);
        $centre->addCommitteeMember($old);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        // El listado ofrece «Sustituir» en cada docente.
        $crawler = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso');
        self::assertSelectorExists('a[href="/centro/' . $centreId . '/docentes-curso/' . $new->getId()->toRfc4122() . '/sustituir"]');

        $crawler = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso/' . $new->getId()->toRfc4122() . '/sustituir');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="replaces"]');
        $token = $crawler->filter('form [name="_token"]')->first()->attr('value');

        // Sin elegir a nadie (o a sí mismo) no se hace nada.
        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/' . $new->getId()->toRfc4122() . '/sustituir', [
            '_token' => $token, 'replaces' => $new->getId()->toRfc4122(),
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Elige un docente del curso distinto');

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/' . $new->getId()->toRfc4122() . '/sustituir', [
            '_token' => $token, 'replaces' => $old->getId()->toRfc4122(),
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $newAgain = $this->em->getRepository(\App\Entity\Teacher::class)->findOneBy(['username' => 'sustituto.act']);
        $centreNow = $this->em->find(\App\Entity\EducationalCentre::class, $centre->getId());
        $groupNow  = $this->em->find(\App\Entity\Group::class, $group->getId());
        $slotNow   = $this->em->find(\App\Entity\TimeSlot::class, $slot->getId());
        self::assertTrue($centreNow->getCommitteeMembers()->contains($newAgain));
        self::assertTrue($groupNow->getTutors()->contains($newAgain));
        self::assertTrue($groupNow->hasTeacherSubject($newAgain, 'Biología'));
        self::assertTrue($slotNow->getGuards()->contains($newAgain));
    }

    public function testReplaceActionRejectsATeacherOutsideTheYearAndABadToken(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $outsider = $this->makeTeacher('fuera.act');
        $new      = $this->makeTeacher('dentro.act');
        $this->persist($admin, $centre, $year, $outsider, $new);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($new);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        // Un docente que no es del curso no se puede sustituir desde aquí…
        $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso/' . $outsider->getId()->toRfc4122() . '/sustituir');
        self::assertResponseStatusCodeSame(404);

        // …y el token ha de ser válido.
        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/' . $new->getId()->toRfc4122() . '/sustituir', [
            '_token' => 'invalido', 'replaces' => $outsider->getId()->toRfc4122(),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAddUnknownUsernameCarriesTheReplacedTeacherToTheRegisterForm(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $old = $this->makeTeacher('sustituido.reg');
        $this->persist($admin, $centre, $year, $old);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($old);
        $centre->addCommitteeMember($old);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso');
        $token    = $crawler->filterXPath('//form[.//input[@id="teacher-username"]]//input[@name="_token"]')->attr('value');
        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/a%C3%B1adir', [
            '_token'   => $token,
            'username' => 'nuevo.reg',
            'replaces' => $old->getId()->toRfc4122(),
        ]);
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        // El formulario de registro llega con el docente sustituido ya elegido; al guardar, el nuevo hereda sus perfiles.
        self::assertSelectorExists('select[name="replaces"] option[selected][value="' . $old->getId()->toRfc4122() . '"]');
        $token = $crawler->filter('[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/registrar', [
            '_token'      => $token,
            'first_name'  => 'Nuevo',
            'last_name'   => 'Registrado',
            'username'    => 'nuevo.reg',
            'auth_method' => 'external',
            'active'      => '1',
            'replaces'    => $old->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $created   = $this->em->getRepository(\App\Entity\Teacher::class)->findOneBy(['username' => 'nuevo.reg']);
        $centreNow = $this->em->find(\App\Entity\EducationalCentre::class, $centre->getId());
        self::assertNotNull($created);
        self::assertTrue($centreNow->getCommitteeMembers()->contains($created));
    }

    public function testAddUnknownUsernameRedirectsToRegister(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/a%C3%B1adir', [
            '_token'   => $token,
            'username' => 'desconocido',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/registrar', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAddWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/a%C3%B1adir', [
            '_token'   => 'token-invalido',
            'username' => 'teacher.1',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── import ────────────────────────────────────────────────────────────────

    public function testImportGetRendersForm(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/docentes-curso/importar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testImportPostWithValidCsvShowsPreviewWithoutCreatingAnything(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, "\"Empleado/a\",\"Usuario IdEA\"\n\"Garcia, Juan\",\"juan.garcia\"\n");
        $file = new UploadedFile($tmpFile, 'import.csv', 'text/csv', null, true);

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/importar', [
            '_token' => $token,
        ], ['csv' => $file]);

        // Ahora hay una vista previa: hasta confirmarla no se crea nada.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Garcia, Juan');
        $this->em->clear();
        self::assertNull($this->em->getRepository(Teacher::class)->findOneBy(['username' => 'juan.garcia']));

        @unlink($tmpFile);
    }

    public function testImportPostWithNoFileRendersFormAgain(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso/importar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/importar', [
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
        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/importar', [
            '_token' => 'token-invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── importAssignments ─────────────────────────────────────────────────────

    public function testImportAssignmentsGetRendersForm(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/docentes-curso/importar-asignaciones');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testImportAssignmentsPostWithValidCsvRedirects(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso/importar-asignaciones');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, "\"Unidad\",\"Profesor/a\",\"Materia\"\n\"DAW1A\",\"Garcia, Juan\",\"Matemáticas\"\n");
        $file = new UploadedFile($tmpFile, 'assignments.csv', 'text/csv', null, true);

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/importar-asignaciones', [
            '_token' => $token,
        ], ['csv' => $file]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/docentes-curso', (string) $this->client->getResponse()->headers->get('Location'));

        @unlink($tmpFile);
    }

    public function testImportAssignmentsPostWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/importar-asignaciones', [
            '_token' => 'token-invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportAssignmentsPostWithoutMateriaColumnRedirectsWithMissingColumnError(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso/importar-asignaciones');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, "\"Unidad\",\"Profesor/a\"\n\"DAW1A\",\"Garcia, Juan\"\n");
        $file = new UploadedFile($tmpFile, 'assignments.csv', 'text/csv', null, true);

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/importar-asignaciones', [
            '_token' => $token,
        ], ['csv' => $file]);

        self::assertResponseRedirects('/centro/' . $centreId . '/docentes-curso/importar-asignaciones');

        @unlink($tmpFile);
    }

    public function testImportAssignmentsPostSkipsRowWhenTeacherAlreadyHasSubjectInGroup(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('DAW1A')->setCourse($course);
        $teacher = (new Teacher(new PersonName('Juan', 'Garcia')))->setUsername('garcia.juan');
        $group->addTeacher($teacher, 'Matemáticas');
        $this->persist($admin, $centre, $year, $course, $group, $teacher);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso/importar-asignaciones');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $tmpFile = tempnam(sys_get_temp_dir(), 'gestconv_test_');
        file_put_contents($tmpFile, "\"Unidad\",\"Profesor/a\",\"Materia\"\n\"DAW1A\",\"Garcia, Juan\",\"Matemáticas\"\n");
        $file = new UploadedFile($tmpFile, 'assignments.csv', 'text/csv', null, true);

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/importar-asignaciones', [
            '_token' => $token,
        ], ['csv' => $file]);

        self::assertResponseRedirects();

        $this->em->clear();
        $reloadedGroup = $this->em->getRepository(Group::class)->find($group->getId());
        self::assertCount(1, $reloadedGroup->getTeacherAssignments());

        @unlink($tmpFile);
    }

    // ── register ──────────────────────────────────────────────────────────────

    public function testRegisterGetRendersForm(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $this->client->request('GET', '/centro/' . $centre->getId()->toRfc4122() . '/docentes-curso/registrar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testRegisterPostCreatesTeacherAndRedirects(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso/registrar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/registrar', [
            '_token'      => $token,
            'first_name'  => 'Juan',
            'last_name'   => 'Garcia',
            'username'    => 'juan.garcia',
            'email'       => '',
            'password'    => 'secret123',
            'auth_method' => 'local',
            'active'      => '1',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/docentes-curso', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testRegisterPostWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/registrar', [
            '_token'     => 'token-invalido',
            'first_name' => 'Juan',
            'last_name'  => 'Garcia',
            'username'   => 'juan.garcia',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRegisterPostWithEmptyFirstNameRendersFormAgain(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $this->persist($admin, $centre, $year);
        $centre->setActiveAcademicYear($year);
        $this->flush();
        $this->loginAs($admin);

        $centreId = $centre->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso/registrar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/registrar', [
            '_token'      => $token,
            'first_name'  => '',
            'last_name'   => 'Garcia',
            'username'    => 'juan.garcia',
            'password'    => 'secret123',
            'auth_method' => 'local',
        ]);

        self::assertResponseIsSuccessful();
    }

    // ── subjects ──────────────────────────────────────────────────────────────

    public function testSubjectsPageIsAccessibleToAdminAndShowsAssignments(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $teacher = $this->makeTeacher('teacher.1');
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $group->addTeacher($teacher, 'Matemáticas');

        $this->persist($admin, $centre, $year, $course, $group, $teacher);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);
        $this->flush();
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $teacherId = $teacher->getId()->toRfc4122();
        $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso/' . $teacherId . '/materias');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Materias asignadas', $html);
        self::assertStringContainsString('Matemáticas', $html);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveRemovesTeacherFromYearAndRedirects(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $teacher = $this->makeTeacher('teacher.1');
        $this->persist($admin, $centre, $year, $teacher);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);
        $this->flush();
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $teacherId = $teacher->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/centro/' . $centreId . '/docentes-curso');
        $token     = $crawler->filter('form[action$="/' . $teacherId . '/quitar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/' . $teacherId . '/quitar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/docentes-curso', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testRemoveWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $year] = $this->makeCentreWithYear();
        $teacher = $this->makeTeacher('teacher.1');
        $this->persist($admin, $centre, $year, $teacher);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);
        $this->flush();
        $this->loginAs($admin);

        $centreId  = $centre->getId()->toRfc4122();
        $teacherId = $teacher->getId()->toRfc4122();
        $this->client->request('POST', '/centro/' . $centreId . '/docentes-curso/' . $teacherId . '/quitar', [
            '_token' => 'token-invalido',
        ]);

        self::assertResponseStatusCodeSame(403);
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
}
