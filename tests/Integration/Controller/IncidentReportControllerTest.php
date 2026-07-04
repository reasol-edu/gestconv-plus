<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class IncidentReportControllerTest extends ControllerTestCase
{
    private int $nextReportNumber = 0;
    private static int $scenarioCounter = 0;
    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToAnyTeacher(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes');

        self::assertResponseIsSuccessful();
    }

    public function testIndexWithoutCentreRedirectsToSelection(): void
    {
        $teacher = $this->makeTeacher('no.centre');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/partes');

        self::assertResponseRedirects();
        self::assertStringContainsString('/centro', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testIndexRedirectsUnauthenticated(): void
    {
        $this->client->request('GET', '/partes');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // ── new ───────────────────────────────────────────────────────────────────

    public function testNewGetRendersFormWithBehaviors(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
        self::assertSelectorExists('input[name="behaviors[]"]');
    }

    public function testNewPostCreatesOneReportPerStudent(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $studentPair = $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'             => $token,
            'students'           => [$studentPair],
            'behaviors'          => [$behavior->getId()->toRfc4122()],
            'occurred_at'        => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description'        => '<p>Incidente de prueba.</p>',
            'expelled_from_class' => '0',
        ]);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/partes/creados?ids=', $location);

        $this->em->clear();
        $reports = $this->em->getRepository(IncidentReport::class)->findAll();
        self::assertCount(1, $reports);
        self::assertSame($student->getId()->toRfc4122(), $reports[0]->getStudent()->getId()->toRfc4122());
    }

    public function testNewPostWithTwoStudentsCreatesTwoReports(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $student2 = (new Student(new PersonName('Pedro', 'López')))->setStudentId('NIE-002');
        $this->persist($student2);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $pair1 = $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();
        $pair2 = $student2->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'      => $token,
            'students'    => [$pair1, $pair2],
            'behaviors'   => [$behavior->getId()->toRfc4122()],
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description' => '<p>Incidente múltiple.</p>',
        ]);

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/partes/creados?ids=', $location);
        self::assertCount(2, explode(',', substr($location, strlen('/partes/creados?ids='))));

        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(IncidentReport::class)->findAll());
    }

    public function testCreatedPageListsReports(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/creados?ids=' . $report->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $student->getName()->getLastName());
        self::assertSelectorExists('a[href="/partes/' . $report->getId()->toRfc4122() . '"]');
    }

    public function testCreatedPageWithUnknownIdsRedirectsToIndex(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/creados?ids=00000000-0000-0000-0000-000000000000');

        self::assertResponseRedirects('/partes');
    }

    public function testNewPostWithNoStudentsShowsError(): void
    {
        [$teacher, $centre, , , $behavior] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/nuevo', [
            '_token'      => $token,
            'students'    => [],
            'behaviors'   => [$behavior->getId()->toRfc4122()],
            'description' => '<p>Test.</p>',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'estudiante');
    }

    public function testNewPostWithNoBehaviorsShowsError(): void
    {
        [$teacher, $centre, $group, $student] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $pair = $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'      => $token,
            'students'    => [$pair],
            'behaviors'   => [],
            'description' => '<p>Test.</p>',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'conducta');
    }

    public function testNewPostIgnoresGroupFromAnotherCentre(): void
    {
        [$teacher, $centre, , , $behavior] = $this->makeScenario();
        [, , $otherGroup, $otherStudent] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $pair = $otherStudent->getId()->toRfc4122() . '::' . $otherGroup->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'              => $token,
            'students'            => [$pair],
            'behaviors'           => [$behavior->getId()->toRfc4122()],
            'description'         => '<p>Test.</p>',
            'expelled_from_class' => '0',
        ]);

        // El par estudiante/grupo de otro centro se descarta: no se crea ningún
        // parte y se vuelve al formulario con un error.
        self::assertResponseRedirects('/partes/nuevo');

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(IncidentReport::class)->findAll());
    }

    public function testNewPostWithInvalidCsrfIsDenied(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('POST', '/partes/nuevo', [
            '_token'      => 'invalid-token',
            'description' => '<p>Test.</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewGetShowsTeacherFieldToCentreAdmin(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $cadmin = $this->makeTeacher('cadmin.new.fields');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();
        $this->loginAs($cadmin, $centre);

        $this->client->request('GET', '/partes/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="registered_by"]');
    }

    public function testNewGetHidesTeacherFieldFromRegularTeacher(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/nuevo');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="registered_by"]');
    }

    public function testNewPostAsCentreAdminAssignsChosenTeacher(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $year = $group->getProgrammeYear()->getProgramme()->getAcademicYear();

        $cadmin     = $this->makeTeacher('cadmin.new.assign');
        $newTeacher = $this->makeTeacher('new.teacher.assign');
        $this->persist($cadmin, $newTeacher);
        $centre->addAdmin($cadmin);
        $year->addTeacher($newTeacher);
        $this->flush();
        $this->loginAs($cadmin, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $studentPair = $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'        => $token,
            'students'      => [$studentPair],
            'behaviors'     => [$behavior->getId()->toRfc4122()],
            'occurred_at'   => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description'   => '<p>Registrado por otro docente.</p>',
            'registered_by' => $newTeacher->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/partes/creados?ids=', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        $reports = $this->em->getRepository(IncidentReport::class)->findAll();
        self::assertCount(1, $reports);
        self::assertSame($newTeacher->getId()->toRfc4122(), $reports[0]->getRegisteredBy()->getId()->toRfc4122());
    }

    public function testNewPostAsRegularTeacherCannotChooseTeacher(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $otherTeacher = $this->makeTeacher('other.new.assign');
        $this->persist($otherTeacher);
        $group->getProgrammeYear()->getProgramme()->getAcademicYear()->addTeacher($otherTeacher);
        $this->flush();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $studentPair = $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'        => $token,
            'students'      => [$studentPair],
            'behaviors'     => [$behavior->getId()->toRfc4122()],
            'occurred_at'   => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description'   => '<p>Intento de asignación.</p>',
            'registered_by' => $otherTeacher->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/partes/creados?ids=', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        $reports = $this->em->getRepository(IncidentReport::class)->findAll();
        self::assertCount(1, $reports);
        self::assertSame($teacher->getId()->toRfc4122(), $reports[0]->getRegisteredBy()->getId()->toRfc4122());
    }

    public function testNewPostWithInvalidTeacherIdShowsError(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $cadmin = $this->makeTeacher('cadmin.new.invalid');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();
        $this->loginAs($cadmin, $centre);

        $crawler = $this->client->request('GET', '/partes/nuevo');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $studentPair = $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122();

        $this->client->request('POST', '/partes/nuevo', [
            '_token'        => $token,
            'students'      => [$studentPair],
            'behaviors'     => [$behavior->getId()->toRfc4122()],
            'occurred_at'   => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description'   => '<p>Test.</p>',
            'registered_by' => '00000000-0000-0000-0000-000000000000',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'docente');
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function testShowIsAccessibleToCreator(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testShowIsDeniedToUnrelatedTeacher(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $other  = $this->makeTeacher('other.teacher');
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->persist($other);
        $this->loginAs($other, $centre);

        $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(403);
    }

    public function testShowIsAccessibleToCentreAdmin(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $cadmin = $this->makeTeacher('cadmin.show');
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();
        $this->loginAs($cadmin, $centre);

        $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testShowDisplaysCommunicationHistory(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $method = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $communication = Communication::forIncidentReport(
            $report, $method, $teacher, new \DateTimeImmutable(), CommunicationResult::Notified, 'Habló con la madre.',
        );
        $this->persist($report, $method, $communication);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Llamada telefónica');
        self::assertSelectorTextContains('body', 'Habló con la madre.');
    }

    public function testShowWithoutCommunicationsDisplaysEmptyHistory(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->persist($report);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Todavía no se ha registrado ninguna comunicación.');
    }

    public function testShowReturns404ForNonExistentReport(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditGetIsAccessibleToCreator(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122() . '/editar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testEditGetIsDeniedToUnrelatedTeacher(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $other  = $this->makeTeacher('other.edit');
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->persist($other);
        $this->loginAs($other, $centre);

        $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditPostSavesChanges(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($teacher, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/partes/' . $reportId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/editar', [
            '_token'      => $token,
            'behaviors'   => [$behavior->getId()->toRfc4122()],
            'occurred_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description' => '<p>Descripción actualizada.</p>',
        ]);

        self::assertResponseRedirects('/partes/' . $reportId);

        $this->em->clear();
        $updated = $this->em->find(IncidentReport::class, $report->getId());
        self::assertNotNull($updated);
        self::assertSame('<p>Descripción actualizada.</p>', $updated->getDescription());
    }

    public function testEditGetShowsReassignFieldsToCentreAdmin(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $cadmin = $this->makeTeacher('cadmin.edit.fields');
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();
        $this->loginAs($cadmin, $centre);

        $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122() . '/editar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="registered_by"]');
        self::assertSelectorExists('select[name="student_group"]');
    }

    public function testEditGetHidesReassignFieldsFromCreator(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/' . $report->getId()->toRfc4122() . '/editar');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="registered_by"]');
        self::assertSelectorNotExists('select[name="student_group"]');
    }

    public function testEditPostAsCentreAdminReassignsTeacherAndStudent(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $year = $group->getProgrammeYear()->getProgramme()->getAcademicYear();

        $cadmin      = $this->makeTeacher('cadmin.reassign');
        $newTeacher  = $this->makeTeacher('new.teacher.reassign');
        $newStudent  = (new \App\Entity\Student(new PersonName('Marta', 'Ruiz')))->setStudentId('NIE-REASSIGN');
        $this->persist($cadmin, $newTeacher, $newStudent);
        $centre->addAdmin($cadmin);
        $year->addTeacher($newTeacher);
        $newStudent->addGroup($group);
        $this->flush();

        $report   = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($cadmin, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/partes/' . $reportId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/editar', [
            '_token'        => $token,
            'behaviors'     => [$behavior->getId()->toRfc4122()],
            'occurred_at'   => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description'   => '<p>Reasignado.</p>',
            'registered_by' => $newTeacher->getId()->toRfc4122(),
            'student_group' => $newStudent->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects('/partes/' . $reportId);

        $this->em->clear();
        $updated = $this->em->find(IncidentReport::class, $report->getId());
        self::assertNotNull($updated);
        self::assertSame($newTeacher->getId()->toRfc4122(), $updated->getRegisteredBy()->getId()->toRfc4122());
        self::assertSame($newStudent->getId()->toRfc4122(), $updated->getStudent()->getId()->toRfc4122());
    }

    public function testEditPostAsCreatorCannotReassignTeacher(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $otherTeacher = $this->makeTeacher('other.attempt.reassign');
        $this->persist($otherTeacher);
        $group->getProgrammeYear()->getProgramme()->getAcademicYear()->addTeacher($otherTeacher);
        $this->flush();

        $report   = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($teacher, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/partes/' . $reportId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/editar', [
            '_token'        => $token,
            'behaviors'     => [$behavior->getId()->toRfc4122()],
            'occurred_at'   => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description'   => '<p>Intento de reasignación.</p>',
            'registered_by' => $otherTeacher->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects('/partes/' . $reportId);

        $this->em->clear();
        $updated = $this->em->find(IncidentReport::class, $report->getId());
        self::assertNotNull($updated);
        self::assertSame($teacher->getId()->toRfc4122(), $updated->getRegisteredBy()->getId()->toRfc4122());
    }

    public function testEditPostWithInvalidTeacherIdShowsError(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $cadmin = $this->makeTeacher('cadmin.invalid.teacher');
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();

        $report   = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($cadmin, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/partes/' . $reportId . '/editar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/editar', [
            '_token'        => $token,
            'behaviors'     => [$behavior->getId()->toRfc4122()],
            'occurred_at'   => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'description'   => '<p>Test.</p>',
            'registered_by' => '00000000-0000-0000-0000-000000000000',
            'student_group' => $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'docente');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteIsDeniedToCreator(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->loginAs($teacher, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/partes/' . $reportId);
        // The delete form is only shown to admins; use a raw POST to confirm denial
        $this->client->request('POST', '/partes/' . $reportId . '/eliminar', [
            '_token' => 'any-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteIsGrantedToCentreAdmin(): void
    {
        [$teacher, $centre, $group, $student, $behavior] = $this->makeScenario();
        $cadmin = $this->makeTeacher('cadmin.delete');
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->persist($cadmin);
        $centre->addAdmin($cadmin);
        $this->flush();
        $this->loginAs($cadmin, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/partes/' . $reportId);
        $token    = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/partes/' . $reportId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/partes');

        $this->em->clear();
        self::assertNull($this->em->find(IncidentReport::class, $report->getId()));
    }

    public function testNewPreloadsStudentFromQueryParams(): void
    {
        [$teacher, $centre, $group, $student] = $this->makeScenario();
        $group->addStudent($student);
        $this->flush();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/partes/nuevo', [
            'studentId' => $student->getId()->toRfc4122(),
            'groupId'   => $group->getId()->toRfc4122(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            $student->getId()->toRfc4122() . '::' . $group->getId()->toRfc4122(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student, 4: IncidentBehavior}
     */
    private function makeScenario(): array
    {
        $suffix    = (string) ++self::$scenarioCounter;
        $teacher   = $this->makeTeacher('teacher.' . uniqid('', false) . $suffix);
        $centre    = (new EducationalCentre())->setCode('4' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA')->setProgrammeYear($level);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . uniqid('', false));
        $category  = (new \App\Entity\IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName('Contrarias')
            ->setSerious(false)
            ->setPosition(0);
        $behavior  = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Perturbación del normal desarrollo de las actividades')
            ->setPosition(0)
            ->setActive(true);

        $centre->setActiveAcademicYear($year);
        $this->persist($teacher, $centre, $year, $programme, $level, $group, $student, $category, $behavior);

        return [$teacher, $centre, $group, $student, $behavior];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function makeReport(
        Student $student,
        Group $group,
        Teacher $creator,
        IncidentBehavior $behavior,
    ): IncidentReport {
        $academicYear = $group->getProgrammeYear()->getProgramme()->getAcademicYear();

        $report = (new IncidentReport())
            ->setAcademicYear($academicYear)
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test.</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);

        $this->persist($report);

        return $report;
    }
}
