<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class DailyNoteControllerTest extends ControllerTestCase
{
    // ── new ───────────────────────────────────────────────────────────────────

    public function testNewGetShowsForm(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/notas/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testNewGetHidesTeacherFieldFromRegularTeacher(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/notas/nuevo');

        self::assertSelectorNotExists('select[name="registered_by"]');
    }

    public function testNewGetShowsTeacherFieldToCentreAdmin(): void
    {
        [, $centre] = $this->makeScenario();
        $cadmin = $this->makeTeacher('cadmin.new.notes');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();
        $this->loginAs($cadmin, $centre);

        $this->client->request('GET', '/notas/nuevo');

        self::assertSelectorExists('select[name="registered_by"]');
    }

    public function testNewPostCreatesNoteAndRedirectsToCreated(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/notas/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notas/nuevo', [
            '_token'       => $token,
            'student_pair' => $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122(),
            'type_id'      => $type->getId()->toRfc4122(),
            'observations' => 'Llegó 10 minutos tarde.',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/creada', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        $notes = $this->em->getRepository(DailyNote::class)->findAll();
        self::assertCount(1, $notes);
        self::assertSame($teacher->getId()->toRfc4122(), $notes[0]->getRegisteredBy()->getId()->toRfc4122());
        self::assertSame('Llegó 10 minutos tarde.', $notes[0]->getObservations());
    }

    public function testNewPostWithoutStudentShowsError(): void
    {
        [$teacher, $centre, , , $type] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/notas/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notas/nuevo', [
            '_token'  => $token,
            'type_id' => $type->getId()->toRfc4122(),
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(DailyNote::class)->findAll());
    }

    public function testNewPostAsCentreAdminAssignsChosenTeacher(): void
    {
        [, $centre, $group, $student, $type] = $this->makeScenario();
        $cadmin     = $this->makeTeacher('cadmin.new.assign.note');
        $newTeacher = $this->makeTeacher('new.teacher.assign.note');
        $this->persist($cadmin, $newTeacher);
        $centre->addAdmin($cadmin);
        $centre->getActiveAcademicYear()->addTeacher($newTeacher);
        $this->flush();
        $this->loginAs($cadmin, $centre);

        $crawler = $this->client->request('GET', '/notas/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notas/nuevo', [
            '_token'        => $token,
            'student_pair'  => $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122(),
            'type_id'       => $type->getId()->toRfc4122(),
            'registered_by' => $newTeacher->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $notes = $this->em->getRepository(DailyNote::class)->findAll();
        self::assertCount(1, $notes);
        self::assertSame($newTeacher->getId()->toRfc4122(), $notes[0]->getRegisteredBy()->getId()->toRfc4122());
    }

    // ── created ───────────────────────────────────────────────────────────────

    public function testCreatedNeverShowsReportButtonEvenAtThreshold(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario(occurrencesForReport: 1);
        $note = $this->makeNote($centre, $student, $group, $type, $teacher);
        $this->persist($note);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/notas/' . $note->getId()->toRfc4122() . '/creada');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/partes/nuevo"]');
        self::assertStringContainsString('1 de 1', $crawler->filter('body')->text());
    }

    public function testCreatedShowsOccurrenceCountBelowThreshold(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario(occurrencesForReport: 5);
        $note = $this->makeNote($centre, $student, $group, $type, $teacher);
        $this->persist($note);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/notas/' . $note->getId()->toRfc4122() . '/creada');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/partes/nuevo"]');
        self::assertStringContainsString('1 de 5', $crawler->filter('body')->text());
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditObservationsAllowedForOwnerWithinWindow(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $note = $this->makeNote($centre, $student, $group, $type, $teacher);
        $this->persist($note);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/notas/' . $note->getId()->toRfc4122() . '/editar');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notas/' . $note->getId()->toRfc4122() . '/editar', [
            '_token'       => $token,
            'observations' => 'Observación corregida.',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $updated = $this->em->find(DailyNote::class, $note->getId());
        self::assertNotNull($updated);
        self::assertSame('Observación corregida.', $updated->getObservations());
    }

    public function testEditDeniedForOwnerAfterWindow(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $note = $this->makeNote($centre, $student, $group, $type, $teacher);
        $note->setCreatedAt(new \DateTimeImmutable('-1 hour'));
        $this->persist($note);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/notas/' . $note->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditDeniedForUnrelatedTeacher(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $other = $this->makeTeacher('other.edit.note');
        $note  = $this->makeNote($centre, $student, $group, $type, $teacher);
        $this->persist($other, $note);
        $this->loginAs($other, $centre);

        $this->client->request('GET', '/notas/' . $note->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditFullFormAllowedForAdminRegardlessOfAge(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $cadmin = $this->makeTeacher('cadmin.edit.note');
        $note   = $this->makeNote($centre, $student, $group, $type, $teacher);
        $note->setCreatedAt(new \DateTimeImmutable('-1 hour'));
        $this->persist($cadmin, $note);
        $centre->addAdmin($cadmin);
        $this->flush();
        $this->loginAs($cadmin, $centre);

        $crawler = $this->client->request('GET', '/notas/' . $note->getId()->toRfc4122() . '/editar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="active"]');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteAllowedForOwnerWithinWindow(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $note = $this->makeNote($centre, $student, $group, $type, $teacher);
        $this->persist($note);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/notas/' . $note->getId()->toRfc4122() . '/editar');
        $token   = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notas/' . $note->getId()->toRfc4122() . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull($this->em->find(DailyNote::class, $note->getId()));
    }

    public function testDeleteDeniedForOwnerAfterWindow(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $note = $this->makeNote($centre, $student, $group, $type, $teacher);
        $note->setCreatedAt(new \DateTimeImmutable('-1 hour'));
        $this->persist($note);
        $this->loginAs($teacher, $centre);

        $this->client->request('POST', '/notas/' . $note->getId()->toRfc4122() . '/eliminar', [
            '_token' => 'irrelevant-because-denied-before-csrf-check-or-not',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->find(DailyNote::class, $note->getId()));
    }

    // ── deactivate ───────────────────────────────────────────────────────────

    public function testDeactivateOneAllowedForTutor(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $tutor = $this->makeTeacher('tutor.deactivate.note');
        $group->addTutor($tutor);
        $note = $this->makeNote($centre, $student, $group, $type, $teacher);
        $this->persist($tutor, $note);
        $this->loginAs($tutor, $centre);

        $crawler = $this->client->request('GET', '/alumnado/' . $student->getId()->toRfc4122());
        $token   = $crawler->filter('form[action$="/desactivar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notas/' . $note->getId()->toRfc4122() . '/desactivar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $updated = $this->em->find(DailyNote::class, $note->getId());
        self::assertNotNull($updated);
        self::assertFalse($updated->isActive());
    }

    public function testDeactivateOneDeniedForUnrelatedTeacher(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $other = $this->makeTeacher('other.deactivate.note');
        $note  = $this->makeNote($centre, $student, $group, $type, $teacher);
        $this->persist($other, $note);
        $this->loginAs($other, $centre);

        $this->client->request('POST', '/notas/' . $note->getId()->toRfc4122() . '/desactivar', [
            '_token' => 'whatever',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeactivateAllOfTypeMarksAllActiveNotes(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $tutor = $this->makeTeacher('tutor.deactivate.all');
        $group->addTutor($tutor);
        $note1 = $this->makeNote($centre, $student, $group, $type, $teacher);
        $note2 = $this->makeNote($centre, $student, $group, $type, $teacher);
        $this->persist($tutor, $note1, $note2);
        $this->loginAs($tutor, $centre);

        $studentId = $student->getId()->toRfc4122();
        $typeId    = $type->getId()->toRfc4122();

        $crawler = $this->client->request('GET', '/notas?tab=students&typeId=' . $typeId);
        $token   = $crawler->filter('form[action$="/desactivar-tipo"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notas/desactivar-tipo', [
            '_token'    => $token,
            'studentId' => $studentId,
            'typeId'    => $typeId,
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $updated1 = $this->em->find(DailyNote::class, $note1->getId());
        $updated2 = $this->em->find(DailyNote::class, $note2->getId());
        self::assertFalse($updated1->isActive());
        self::assertFalse($updated2->isActive());
    }

    public function testDeactivateAllOfTypeDeniedForNonTutorNonAdmin(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $other = $this->makeTeacher('other.deactivate.all');
        $this->persist($other);
        $this->loginAs($other, $centre);

        // La comprobación de permisos ocurre antes que la de CSRF, así que un token
        // cualquiera basta para probar que se deniega el acceso.
        $this->client->request('POST', '/notas/desactivar-tipo', [
            '_token'    => 'whatever',
            'studentId' => $student->getId()->toRfc4122(),
            'typeId'    => $type->getId()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student, 4: DailyNoteType} */
    private function makeScenario(int $occurrencesForReport = 3): array
    {
        $suffix   = uniqid('', false);
        $teacher  = $this->makeTeacher('teacher.dn.' . $suffix);
        $centre   = (new EducationalCentre())->setCode('5' . substr($suffix, 0, 7))->setName('IES Test')->setCity('Sevilla');
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course   = (new Course())->setName('DAW')->setAcademicYear($year);
        $group    = (new Group())->setName('1ºA')->setCourse($course);
        $student  = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . $suffix);
        $type     = (new DailyNoteType())->setEducationalCentre($centre)->setName('Retraso')->setOccurrencesForReport($occurrencesForReport)->setPosition(0);

        $centre->setActiveAcademicYear($year);
        $student->addGroup($group);
        $this->persist($teacher, $centre, $year, $course, $group, $student, $type);

        return [$teacher, $centre, $group, $student, $type];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function makeNote(EducationalCentre $centre, Student $student, Group $group, DailyNoteType $type, Teacher $teacher): DailyNote
    {
        return (new DailyNote())
            ->setAcademicYear($centre->getActiveAcademicYear())
            ->setStudent($student)
            ->setGroup($group)
            ->setType($type)
            ->setRegisteredBy($teacher);
    }
}
