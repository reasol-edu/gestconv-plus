<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Course;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class DailyNoteControllerTest extends ControllerTestCase
{
    private static int $scenarioCounter = 0;

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

    // ── varios estudiantes a la vez ───────────────────────────────────────────

    private function pair(Student $student, Group $group): string
    {
        return $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();
    }

    private function formToken(): string
    {
        $crawler = $this->client->request('GET', '/notas/nuevo');

        return (string) $crawler->filter('[name="_token"]')->first()->attr('value');
    }

    public function testNewPostWithSeveralStudentsCreatesOneNoteEachAndListsThemInTheSummary(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $other = (new Student(new PersonName('Luis', 'Pérez')))->setStudentId('NIE-L-' . uniqid('', false));
        $other->addGroup($group);
        $this->persist($other);
        $this->loginAs($teacher, $centre);
        $token = $this->formToken();

        $this->client->request('POST', '/notas/nuevo', [
            '_token'       => $token,
            'students'     => [$this->pair($student, $group), $this->pair($other, $group)],
            'type_id'      => $type->getId()->toRfc4122(),
            'observations' => 'Sin material.',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/notas/creadas?ids=', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        $notes = $this->em->getRepository(DailyNote::class)->findAll();
        self::assertCount(2, $notes);
        foreach ($notes as $note) {
            self::assertSame($teacher->getId()->toRfc4122(), $note->getRegisteredBy()->getId()->toRfc4122());
            self::assertSame('Sin material.', $note->getObservations());
        }

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Notas registradas', $crawler->text());
        self::assertStringContainsString('García, Ana', $crawler->text());
        self::assertStringContainsString('Pérez, Luis', $crawler->text());
    }

    public function testNewPostIgnoresDuplicatesAndPairsFromAnotherCentre(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        [, , $foreignGroup, $foreignStudent]          = $this->makeScenario();
        $this->loginAs($teacher, $centre);
        $token = $this->formToken();

        $this->client->request('POST', '/notas/nuevo', [
            '_token'   => $token,
            'students' => [$this->pair($student, $group), $this->pair($student, $group), $this->pair($foreignStudent, $foreignGroup)],
            'type_id'  => $type->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/creada', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertStringNotContainsString('/creadas', (string) $this->client->getResponse()->headers->get('Location'));
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(DailyNote::class)->findAll());
    }

    public function testNewPostWithMoreStudentsThanTheLimitCreatesNothing(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $pairs = [$this->pair($student, $group)];
        for ($i = 0; $i < 50; ++$i) {
            $extra = (new Student(new PersonName('Extra' . $i, 'Alumno')))->setStudentId('NIE-X-' . $i . uniqid('', false));
            $extra->addGroup($group);
            $this->persist($extra);
            $pairs[] = $this->pair($extra, $group);
        }
        $this->loginAs($teacher, $centre);
        $token = $this->formToken();

        $this->client->request('POST', '/notas/nuevo', ['_token' => $token, 'students' => $pairs, 'type_id' => $type->getId()->toRfc4122()]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('como máximo', $this->client->getResponse()->getContent() ?: '');
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(DailyNote::class)->findAll());
    }

    public function testCreatedManyOnlyListsNotesTheViewerCanSee(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario();
        $stranger = $this->makeTeacher('stranger.dn.' . uniqid('', false));
        $other    = (new Student(new PersonName('Luis', 'Pérez')))->setStudentId('NIE-L-' . uniqid('', false));
        $other->addGroup($group);
        $this->persist($stranger, $other);
        $mine   = $this->makeNote($centre, $student, $group, $type, $teacher);
        $theirs = $this->makeNote($centre, $other, $group, $type, $stranger);
        $this->persist($mine, $theirs);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/notas/creadas?ids=' . $mine->getId()->toRfc4122() . ',' . $theirs->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('García, Ana', $crawler->text());
        self::assertStringNotContainsString('Pérez, Luis', $crawler->text());

        $this->client->request('GET', '/notas/creadas?ids=' . $theirs->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTypeStatusFlagsATypeThatWouldReachTheThresholdForAnySelectedStudent(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario(2);
        $other = (new Student(new PersonName('Luis', 'Pérez')))->setStudentId('NIE-L-' . uniqid('', false));
        $other->addGroup($group);
        $this->persist($other);
        $this->persist($this->makeNote($centre, $student, $group, $type, $teacher));
        $this->loginAs($teacher, $centre);

        $flag = function (string $query) use ($type): bool {
            $this->client->request('GET', '/notas/estado-tipos?' . $query);
            self::assertResponseIsSuccessful();
            $data = json_decode((string) $this->client->getResponse()->getContent(), true);
            foreach ($data['types'] as $t) {
                if ($t['id'] === $type->getId()->toRfc4122()) {
                    return $t['warn'];
                }
            }
            self::fail('Tipo no devuelto.');
        };

        $withNote = $student->getId()->toRfc4122();
        $without  = $other->getId()->toRfc4122();
        self::assertTrue($flag('studentIds=' . $without . ',' . $withNote), 'Basta con que lo alcance uno de los estudiantes.');
        self::assertTrue($flag('studentId=' . $withNote), 'El parámetro de un solo estudiante se sigue aceptando.');
        self::assertFalse($flag('studentIds=' . $without));
    }

    /** El estado de tipos y el resumen de notas hacen las mismas consultas con 12 estudiantes que con uno (sin N+1). */
    public function testTypeStatusAndCreatedManyQueryCountDoNotGrowWithTheNumberOfStudents(): void
    {
        [$teacher, $centre, $group, $student, $type] = $this->makeScenario(2);
        $ids = ['teacher' => $teacher->getId(), 'centre' => $centre->getId(), 'group' => $group->getId(), 'type' => $type->getId()];
        $first = $this->makeNote($centre, $student, $group, $type, $teacher);
        $this->persist($first);
        $studentIds = [$student->getId()->toRfc4122()];
        $noteIds    = [$first->getId()->toRfc4122()];
        $this->loginAs($teacher, $centre);

        $statusUrl = static fn (array $studentIds): string => '/notas/estado-tipos?studentIds=' . implode(',', $studentIds);
        $manyUrl   = static fn (array $noteIds): string => '/notas/creadas?ids=' . implode(',', $noteIds);
        $statusOne = $this->countQueries($statusUrl($studentIds));
        $manyOne   = $this->countQueries($manyUrl($noteIds));

        $teacherRef = $this->em->find(Teacher::class, $ids['teacher']);
        $groupRef   = $this->em->find(Group::class, $ids['group']);
        $typeRef    = $this->em->find(DailyNoteType::class, $ids['type']);
        $centreRef  = $this->em->find(EducationalCentre::class, $ids['centre']);
        for ($i = 0; $i < 11; ++$i) {
            $extra = (new Student(new PersonName('Extra' . $i, 'Alumno')))->setStudentId('NIE-N1-' . $i . uniqid('', false));
            $extra->addGroup($groupRef);
            $this->persist($extra);
            $note = $this->makeNote($centreRef, $extra, $groupRef, $typeRef, $teacherRef);
            $this->persist($note);
            $studentIds[] = $extra->getId()->toRfc4122();
            $noteIds[]    = $note->getId()->toRfc4122();
        }

        self::assertLessThanOrEqual($statusOne, $this->countQueries($statusUrl($studentIds)), '/notas/estado-tipos hace consultas por estudiante.');
        self::assertLessThanOrEqual($manyOne, $this->countQueries($manyUrl($noteIds)), '/notas/creadas hace consultas por nota.');
    }

    public function testFormOffersQuickSelectionByDefault(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/notas/nuevo');

        self::assertSelectorExists('[data-controller="quick-selection"][data-quick-selection-select-id-value="student-select"]');
    }

    public function testFormHidesQuickSelectionWhenTheCentreSwitchesItOff(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $definition = (new SettingDefinition())->setKey('display.quick_student_selection')->setType(SettingType::Boolean)
            ->setCategory('settings.category.display')->setDefaultValue('true')
            ->setGlobalScope(true)->setCentreScope(true)->setTeacherScope(true);
        $this->persist($definition, (new CentreSettingValue())->setDefinition($definition)->setCentre($centre)->setValue('false'));
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/notas/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-controller="quick-selection"]');
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
        $centre   = (new EducationalCentre())->setCode('5' . str_pad((string) ++self::$scenarioCounter, 7, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
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
