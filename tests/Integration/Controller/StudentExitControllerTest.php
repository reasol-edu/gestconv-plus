<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\StudentExit;
use App\Entity\StudentPriorityPass;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

class StudentExitControllerTest extends ControllerTestCase
{
    use ClockSensitiveTrait;
    use InteractsWithLiveComponents;

    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexShowsAuthorizeButtonToAnyTeacher(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['teacher'], $s['centre']);

        $this->client->request('GET', '/salidas');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/salidas/nueva"]');
        self::assertStringNotContainsString('Historial', (string) $this->client->getResponse()->getContent());
    }

    public function testIndexShowsHistoryTabToCentreAdminOnly(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['cadmin'], $s['centre']);

        $this->client->request('GET', '/salidas?tab=history');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Historial', (string) $this->client->getResponse()->getContent());

        // Un docente corriente que pide la pestaña de historial recibe la vista normal.
        $this->loginAs($s['teacher'], $s['centre']);
        $this->client->request('GET', '/salidas?tab=history');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Historial', (string) $this->client->getResponse()->getContent());
    }

    // ── nueva: pasos ──────────────────────────────────────────────────────────

    public function testStepOneListsOwnGroupsFirst(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['teacher'], $s['centre']);

        $this->client->request('GET', '/salidas/nueva');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        // El docente tutoriza 1ºB: aparece antes que 1ºA aunque alfabéticamente vaya después.
        self::assertLessThan(strpos($html, '1ºA'), strpos($html, '1ºB'));
        self::assertStringContainsString('Tus grupos', $html);
    }

    public function testStepTwoListsStudentsWithPassBadgeAndOutMarker(): void
    {
        $s = $this->makeScenario();
        $this->makeExit($s, $s['studentA'], $s['groupA'], $s['other']);
        $this->loginAs($s['teacher'], $s['centre']);

        $this->client->request('GET', '/salidas/nueva?groupId=' . $s['groupA']->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('García, Ana', $html);
        self::assertStringContainsString('PASE', $html);
        self::assertSelectorExists('li a[href*="studentId"]');
        self::assertStringContainsString('Fuera', $html);
    }

    public function testStepThreeShowsPassDescriptionAndReasonGrid(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['teacher'], $s['centre']);

        $this->client->request('GET', $this->newUrl($s['groupA'], $s['studentA']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="note"]', 'Diabetes: puede salir a la enfermería');
        self::assertCount(1, $this->client->getCrawler()->filter('input[type="radio"][name="reason_id"]'), 'Solo el motivo activo');
        self::assertSelectorTextContains('main', 'Servicio');
    }

    public function testStepThreeWithoutPassShowsNoNote(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['teacher'], $s['centre']);

        $this->client->request('GET', $this->newUrl($s['groupB'], $s['studentB']));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[role="note"]');
    }

    public function testStudentOutsideTheGroupFallsBackToStudentStep(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['teacher'], $s['centre']);

        // studentB no es de 1ºA: no se llega al paso del motivo.
        $this->client->request('GET', $this->newUrl($s['groupA'], $s['studentB']));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="reason_id"]');
    }

    // ── nueva: alta ───────────────────────────────────────────────────────────

    public function testPostCreatesExitAndRedirectsToIndex(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['teacher'], $s['centre']);

        $url   = $this->newUrl($s['groupA'], $s['studentA']);
        $token = $this->client->request('GET', $url)->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'       => $token,
            'reason_id'    => $s['reason']->getId()->toRfc4122(),
            'observations' => 'Va con el carné',
        ]);

        self::assertResponseRedirects('/salidas');
        $this->em->clear();
        $exits = $this->em->getRepository(StudentExit::class)->findAll();
        self::assertCount(1, $exits);
        self::assertSame('Va con el carné', $exits[0]->getObservations());
        self::assertSame($s['teacher']->getId()->toRfc4122(), $exits[0]->getAuthorizedBy()->getId()->toRfc4122());
        self::assertSame($s['studentA']->getId()->toRfc4122(), $exits[0]->getStudent()->getId()->toRfc4122());
        self::assertNull($exits[0]->getReturnedAt());
        self::assertNull($exits[0]->getReturnedBy());
    }

    public function testPostWithoutReasonCreatesNothing(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['teacher'], $s['centre']);

        $url   = $this->newUrl($s['groupA'], $s['studentA']);
        $token = $this->client->request('GET', $url)->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(StudentExit::class)->findAll());
    }

    public function testPostWithInactiveReasonCreatesNothing(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['teacher'], $s['centre']);

        $url   = $this->newUrl($s['groupA'], $s['studentA']);
        $token = $this->client->request('GET', $url)->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'    => $token,
            'reason_id' => $s['inactiveReason']->getId()->toRfc4122(),
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(StudentExit::class)->findAll());
    }

    public function testPostIsRejectedWhenTheStudentIsAlreadyOut(): void
    {
        $s = $this->makeScenario();
        $this->makeExit($s, $s['studentA'], $s['groupA'], $s['other']);
        $this->loginAs($s['teacher'], $s['centre']);

        $url   = $this->newUrl($s['groupA'], $s['studentA']);
        $token = $this->client->request('GET', $url)->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'    => $token,
            'reason_id' => $s['reason']->getId()->toRfc4122(),
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(StudentExit::class)->findAll());
    }

    public function testPostWithInvalidCsrfIsDenied(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['teacher'], $s['centre']);

        $this->client->request('POST', $this->newUrl($s['groupA'], $s['studentA']), [
            '_token'    => 'nope',
            'reason_id' => $s['reason']->getId()->toRfc4122(),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNewIsDeniedWhileViewingAPastYear(): void
    {
        $s    = $this->makeScenario();
        $past = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($s['centre']);
        $this->persist($past);
        $this->loginAs($s['cadmin'], $s['centre']);
        $this->viewPastYear($past);

        $this->client->request('GET', '/salidas/nueva');

        self::assertResponseStatusCodeSame(403);
    }

    // ── regreso (acción del componente en vivo) ───────────────────────────────

    public function testAuthorizerCanMarkHisOwnExitAsReturned(): void
    {
        $s    = $this->makeScenario();
        $exit = $this->makeExit($s, $s['studentA'], $s['groupA'], $s['other']);
        $this->loginAs($s['other'], $s['centre']);

        $this->createLiveComponent('ExitCurrentListComponent', ['centre' => $s['centre']], $this->client)
            ->call('markReturned', ['id' => $exit->getId()->toRfc4122()]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(StudentExit::class)->find($exit->getId());
        self::assertNotNull($reloaded?->getReturnedAt());
        self::assertSame($s['other']->getId()->toRfc4122(), $reloaded->getReturnedBy()?->getId()->toRfc4122());
    }

    public function testGroupTutorCanMarkAnotherTeachersExitAsReturned(): void
    {
        $s    = $this->makeScenario();
        $exit = $this->makeExit($s, $s['studentB'], $s['groupB'], $s['other']);
        $this->loginAs($s['teacher'], $s['centre']); // tutor de 1ºB

        $this->createLiveComponent('ExitCurrentListComponent', ['centre' => $s['centre']], $this->client)
            ->call('markReturned', ['id' => $exit->getId()->toRfc4122()]);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(StudentExit::class)->find($exit->getId())?->getReturnedAt());
    }

    public function testUnrelatedTeacherCannotMarkReturned(): void
    {
        $s        = $this->makeScenario();
        $exit     = $this->makeExit($s, $s['studentA'], $s['groupA'], $s['other']);
        $stranger = $this->makeTeacher('stranger');
        $this->persist($stranger);
        $this->loginAs($stranger, $s['centre']);

        $render = $this->createLiveComponent('ExitCurrentListComponent', ['centre' => $s['centre']], $this->client)->render();
        self::assertStringNotContainsString('Ha regresado', $render->toString());

        try {
            $this->createLiveComponent('ExitCurrentListComponent', ['centre' => $s['centre']], $this->client)
                ->call('markReturned', ['id' => $exit->getId()->toRfc4122()]);
            self::fail('Debería haberse denegado el acceso.');
        } catch (\Throwable) {
            // Esperado: 403.
        }

        $this->em->clear();
        self::assertNull($this->em->getRepository(StudentExit::class)->find($exit->getId())?->getReturnedAt());
    }

    public function testCentreAdminCanMarkAnyExitAsReturned(): void
    {
        $s    = $this->makeScenario();
        $exit = $this->makeExit($s, $s['studentA'], $s['groupA'], $s['other']);
        $this->loginAs($s['cadmin'], $s['centre']);

        $this->createLiveComponent('ExitCurrentListComponent', ['centre' => $s['centre']], $this->client)
            ->call('markReturned', ['id' => $exit->getId()->toRfc4122()]);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(StudentExit::class)->find($exit->getId())?->getReturnedAt());
    }

    // ── listado de salidas actuales ───────────────────────────────────────────

    public function testCurrentListSeparatesOwnExitsAndShowsLevels(): void
    {
        self::mockTime('2026-10-14 10:00:00');
        $s = $this->makeScenario();
        $mine  = $this->makeExit($s, $s['studentB'], $s['groupB'], $s['teacher'], '2026-10-14 09:55:00');
        $other = $this->makeExit($s, $s['studentA'], $s['groupA'], $s['other'], '2026-10-14 09:35:00');
        $this->loginAs($s['teacher'], $s['centre']);

        $crawler = $this->createLiveComponent('ExitCurrentListComponent', ['centre' => $s['centre']], $this->client)->render()->crawler();
        $text    = $crawler->text();

        self::assertStringContainsString('Tus salidas ahora', $text);
        self::assertStringContainsString('Otras salidas ahora', $text);
        self::assertStringContainsString('Hace 5 minutos', $text);
        self::assertStringContainsString('Hace 25 minutos', $text);
        self::assertStringContainsString('PASE', $text); // Ana García
        // El nivel (25 min → naranja = 2) viaja en la clase y el valor para el cronómetro.
        self::assertCount(1, $crawler->filter('[data-exit-timer-seconds-value="1500"]'));
        self::assertCount(1, $crawler->filter('[data-exit-timer-seconds-value="300"]'));
        self::assertNotNull($mine);
        self::assertNotNull($other);
    }

    public function testCurrentListHidesOwnSectionWhenEmptyAndIgnoresReturnedExits(): void
    {
        $s = $this->makeScenario();
        $returned = $this->makeExit($s, $s['studentA'], $s['groupA'], $s['teacher']);
        $returned->markReturned($s['teacher']);
        $this->flush();
        $this->loginAs($s['teacher'], $s['centre']);

        $text = $this->createLiveComponent('ExitCurrentListComponent', ['centre' => $s['centre']], $this->client)->render()->crawler()->text();

        self::assertStringNotContainsString('Tus salidas ahora', $text);
        self::assertStringContainsString('Ahora mismo no hay ningún estudiante fuera del aula', $text);
    }

    // ── historial de dirección ────────────────────────────────────────────────

    public function testHistoryComponentIsDeniedToRegularTeacher(): void
    {
        $s = $this->makeScenario();
        $this->loginAs($s['teacher'], $s['centre']);

        $this->expectException(\Throwable::class);
        $this->createLiveComponent('ExitHistoryListComponent', ['centre' => $s['centre']], $this->client)->render();
    }

    public function testHistoryFiltersByReasonStatusAndShowsMinutesOut(): void
    {
        self::mockTime('2026-10-14 12:00:00');
        $s = $this->makeScenario();
        $done = $this->makeExit($s, $s['studentA'], $s['groupA'], $s['other'], '2026-10-14 10:00:00');
        $done->markReturned($s['other'], new \DateTimeImmutable('2026-10-14 10:12:00'));
        $this->makeExit($s, $s['studentB'], $s['groupB'], $s['teacher'], '2026-10-14 11:50:00');
        $this->flush();
        $this->loginAs($s['cadmin'], $s['centre']);

        $component = $this->createLiveComponent('ExitHistoryListComponent', ['centre' => $s['centre']], $this->client);
        $all       = $component->render()->crawler()->text();
        self::assertStringContainsString('García, Ana', $all);
        self::assertStringContainsString('Pérez, Luis', $all);
        self::assertStringContainsString('12 min', $all);

        $component->set('status', 'out');
        $out = $component->render()->crawler()->text();
        self::assertStringNotContainsString('García, Ana', $out);
        self::assertStringContainsString('Pérez, Luis', $out);

        $component->set('status', '')->set('search', 'Garc');
        $searched = $component->render()->crawler()->text();
        self::assertStringContainsString('García, Ana', $searched);
        self::assertStringNotContainsString('Pérez, Luis', $searched);
    }

    // ── ficha del estudiante ──────────────────────────────────────────────────

    public function testStudentSheetShowsExitHistoryAndPassBadge(): void
    {
        $s    = $this->makeScenario();
        $exit = $this->makeExit($s, $s['studentA'], $s['groupA'], $s['other']);
        $exit->markReturned($s['other']);
        $this->flush();
        $this->loginAs($s['cadmin'], $s['centre']);

        $this->client->request('GET', '/alumnado/' . $s['studentA']->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Salidas autorizadas', $html);
        self::assertStringContainsString('PASE', $html);
        self::assertStringContainsString('Servicio', $html);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{teacher: Teacher, other: Teacher, cadmin: Teacher, centre: EducationalCentre, year: AcademicYear,
     *               groupA: Group, groupB: Group, studentA: Student, studentB: Student,
     *               reason: ExitReason, inactiveReason: ExitReason}
     */
    private function makeScenario(): array
    {
        $suffix  = uniqid('', false);
        $teacher = $this->makeTeacher('teacher.exit.' . $suffix);
        $other   = $this->makeTeacher('other.exit.' . $suffix);
        $cadmin  = $this->makeTeacher('cadmin.exit.' . $suffix);
        $centre  = (new EducationalCentre())->setCode('6' . substr($suffix, 0, 7))->setName('IES Test')->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $groupA  = (new Group())->setName('1ºA')->setCourse($course);
        $groupB  = (new Group())->setName('1ºB')->setCourse($course);
        $studentA = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-A-' . $suffix);
        $studentB = (new Student(new PersonName('Luis', 'Pérez')))->setStudentId('NIE-B-' . $suffix);
        $reason  = (new ExitReason())->setEducationalCentre($centre)->setName('Servicio')->setPosition(0);
        $inactive = (new ExitReason())->setEducationalCentre($centre)->setName('Fuente')->setPosition(1)->setActive(false);

        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($cadmin);
        $groupB->addTutor($teacher);
        $studentA->addGroup($groupA);
        $studentB->addGroup($groupB);
        $this->persist($teacher, $other, $cadmin, $centre, $year, $course, $groupA, $groupB, $studentA, $studentB, $reason, $inactive);

        $pass = (new StudentPriorityPass())
            ->setStudent($studentA)
            ->setAcademicYear($year)
            ->setDescription('Diabetes: puede salir a la enfermería');
        $this->persist($pass);

        return [
            'teacher' => $teacher, 'other' => $other, 'cadmin' => $cadmin, 'centre' => $centre, 'year' => $year,
            'groupA' => $groupA, 'groupB' => $groupB, 'studentA' => $studentA, 'studentB' => $studentB,
            'reason' => $reason, 'inactiveReason' => $inactive,
        ];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function newUrl(Group $group, Student $student): string
    {
        return '/salidas/nueva?groupId=' . $group->getId()->toRfc4122() . '&studentId=' . $student->getId()->toRfc4122();
    }

    /** @param array{year: AcademicYear, reason: ExitReason} $s */
    private function makeExit(array $s, Student $student, Group $group, Teacher $by, ?string $at = null): StudentExit
    {
        $exit = (new StudentExit())
            ->setAcademicYear($s['year'])
            ->setStudent($student)
            ->setGroup($group)
            ->setReason($s['reason'])
            ->setAuthorizedBy($by);
        if ($at !== null) {
            $exit->setExitedAt(new \DateTimeImmutable($at));
        }
        $this->persist($exit);

        return $exit;
    }
}
