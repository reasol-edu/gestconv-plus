<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\SetupStepConfirmation;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

/** Lista de comprobación de inicio de curso: página, confirmación manual y aviso en el inicio. */
class CourseSetupControllerTest extends ControllerTestCase
{
    private static int $counter = 0;

    // ── acceso ────────────────────────────────────────────────────────────────

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $w = $this->world();

        $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAnUnprivilegedTeacherIsForbidden(): void
    {
        $w       = $this->world();
        $teacher = $this->teacher('plain');
        $this->persist($teacher);
        $this->loginAs($teacher, $w['centre']);

        $this->client->request('GET', $this->url($w['centre']));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', $this->url($w['centre']) . '/catalogues/confirmar', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAdminOfAnotherCentreIsForbidden(): void
    {
        $mine  = $this->world();
        $other = $this->world();
        $this->loginAs($mine['admin'], $mine['centre']);

        $this->client->request('GET', $this->url($other['centre']));

        self::assertResponseStatusCodeSame(403);
    }

    // ── la página ─────────────────────────────────────────────────────────────

    public function testThePageListsEveryStepWithItsProgress(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Inicio de curso');
        self::assertSelectorCount(10, 'ol > li');
        self::assertSelectorTextContains('[role="progressbar"]', '');
        self::assertSelectorExists('[role="progressbar"][aria-valuenow="16"]');
        // Cada paso lleva a su pantalla.
        self::assertSelectorExists('#step-teachers a[href$="/docentes-curso/importar"]');
        self::assertSelectorExists('#step-students a[href$="/estudiantes/importar"]');
        // Solo los pasos de revisión tienen botón de confirmar.
        self::assertSelectorExists('#step-catalogues form[action$="/inicio-de-curso/catalogues/confirmar"]');
        self::assertSelectorNotExists('#step-teachers form');
    }

    public function testWithoutAnActiveYearTheOtherStepsWait(): void
    {
        $w = $this->world(activeYear: false);
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', $this->url($w['centre']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main, body', 'no tiene un curso académico activo');
        self::assertSelectorExists('#step-active_year a[href$="/cursos"]');
        self::assertSelectorNotExists('#step-teachers a');
        self::assertSelectorNotExists('form[action*="confirmar"]');
    }

    public function testTheCentreHubOffersTheListWithItsProgress(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', '/centro/' . $w['centre']->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href$="/inicio-de-curso"]');
        self::assertSelectorTextContains('a[href$="/inicio-de-curso"]', 'pasos imprescindibles');
    }

    // ── confirmación manual ───────────────────────────────────────────────────

    public function testAManualStepCanBeConfirmedAndUndone(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $crawler = $this->client->request('GET', $this->url($w['centre']));
        $this->client->submit($crawler->filter('#step-catalogues form')->form());
        self::assertResponseRedirects($this->url($w['centre']));

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('#step-catalogues', 'Confirmado por');
        self::assertSelectorExists('#step-catalogues form[action$="/catalogues/desmarcar"]');
        self::assertSelectorExists('[role="progressbar"][aria-valuenow="33"]');

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(SetupStepConfirmation::class)->findAll());

        $this->client->submit($crawler->filter('#step-catalogues form')->form());
        $this->client->followRedirect();
        self::assertSelectorExists('#step-catalogues form[action$="/catalogues/confirmar"]');
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SetupStepConfirmation::class)->findAll());
    }

    public function testConfirmingTwiceDoesNotDuplicate(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $crawler = $this->client->request('GET', $this->url($w['centre']));
        $form    = $crawler->filter('#step-settings form')->form();
        $this->client->submit($form);
        $this->client->submit($form);

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(SetupStepConfirmation::class)->findAll());
    }

    public function testAWrongCsrfTokenIsForbiddenAndNothingIsStored(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('POST', $this->url($w['centre']) . '/catalogues/confirmar', ['_token' => 'falso']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(SetupStepConfirmation::class)->findAll());
    }

    public function testDetectedStepsAndUnknownStepsCannotBeConfirmedByHand(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);
        // Con un token válido de la propia página, para que lo único que falle sea el paso.
        $crawler = $this->client->request('GET', $this->url($w['centre']));
        $token   = (string) $crawler->filter('#step-catalogues input[name="_token"]')->attr('value');

        foreach (['teachers', 'active_year', 'inventado'] as $step) {
            $this->client->request('POST', $this->url($w['centre']) . "/$step/confirmar", ['_token' => $token]);
            self::assertResponseStatusCodeSame(404, $step);
        }
    }

    public function testConfirmingOnlyAffectsTheActiveYearOfThatCentre(): void
    {
        $mine  = $this->world();
        $other = $this->world();
        $this->loginAs($mine['admin'], $mine['centre']);

        $crawler = $this->client->request('GET', $this->url($mine['centre']));
        $this->client->submit($crawler->filter('#step-catalogues form')->form());

        $this->em->clear();
        $stored = $this->em->getRepository(SetupStepConfirmation::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame($mine['year']->getId()->toRfc4122(), $stored[0]->getAcademicYear()->getId()->toRfc4122());
        self::assertNotSame($other['year']->getId()->toRfc4122(), $stored[0]->getAcademicYear()->getId()->toRfc4122());
    }

    // ── aviso en el inicio ────────────────────────────────────────────────────

    public function testTheDashboardShowsTheCardToTheManagementWhileStepsAreMissing(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#setup-card-title');
        self::assertSelectorExists('a[href$="/inicio-de-curso"]');
    }

    public function testTheDashboardShowsTheCardEvenWithoutAnActiveYear(): void
    {
        $w = $this->world(activeYear: false);
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#setup-card-title');
        self::assertSelectorTextContains('#setup-card-title ~ p', 'Crea y activa el curso académico');
    }

    public function testThePlainTeachersDashboardHasNoCardAndNeverQueriesTheChecklist(): void
    {
        $w       = $this->world();
        $teacher = $this->teacher('plain2');
        $this->persist($teacher);
        $this->loginAs($teacher, $w['centre']);

        $this->em->clear();
        $this->client->enableProfiler();
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#setup-card-title');

        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        /** @var \Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector $db */
        $db  = $profile->getCollector('db');
        $sql = implode("\n", array_map(static fn (array $q): string => (string) $q['sql'], array_merge(...array_values($db->getQueries()))));
        self::assertStringNotContainsString('setup_step_confirmation', $sql, 'El profesorado normal no debe pagar ninguna consulta por la lista de inicio de curso.');
    }

    public function testTheCardDisappearsWhenEveryRequiredStepIsDone(): void
    {
        $w = $this->world();
        $this->completeEverything($w);
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#setup-card-title');
    }

    public function testTheChecklistQueryCountDoesNotGrowWithTheData(): void
    {
        $small = $this->world();
        $big   = $this->world();
        for ($i = 0; $i < 25; $i++) {
            $course = (new Course())->setName('Curso ' . $i)->setAcademicYear($big['year']);
            $group  = (new Group())->setName('G' . $i)->setCourse($course);
            $student = (new Student(new PersonName('E' . $i, 'A')))->setStudentId('NIE-scc-' . (++self::$counter));
            $student->addGroup($group);
            $this->persist($course, $group, $student);
        }

        $this->loginAs($small['admin'], $small['centre']);
        $few = $this->countQueries($this->url($small['centre']));
        $this->loginAs($big['admin'], $big['centre']);
        $many = $this->countQueries($this->url($big['centre']));

        self::assertLessThanOrEqual($few, $many, "La lista hace $many consultas con 25 grupos y $few con 1: crece con los datos.");
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function url(EducationalCentre $centre): string
    {
        return '/centro/' . $centre->getId()->toRfc4122() . '/inicio-de-curso';
    }

    private function teacher(string $name): Teacher
    {
        return (new Teacher(new PersonName('Test', ucfirst($name))))->setUsername($name . '.' . uniqid('', false))->setPassword('x');
    }

    /** @return array{centre: EducationalCentre, year: AcademicYear, group: Group, admin: Teacher} */
    private function world(bool $activeYear = true): array
    {
        $n      = ++self::$counter;
        $centre = (new EducationalCentre())->setCode('7' . str_pad((string) $n, 7, '0', STR_PAD_LEFT))->setName('IES Inicio ' . $n)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course = (new Course())->setName('DAW')->setAcademicYear($year);
        $group  = (new Group())->setName('1ºA')->setCourse($course);
        $admin  = $this->teacher('director');
        if ($activeYear) {
            $centre->setActiveAcademicYear($year);
        }
        $centre->addAdmin($admin);
        $this->persist($centre, $year, $course, $group, $admin);

        return ['centre' => $centre, 'year' => $year, 'group' => $group, 'admin' => $admin];
    }

    /** @param array{centre: EducationalCentre, year: AcademicYear, group: Group, admin: Teacher} $w */
    private function completeEverything(array $w): void
    {
        $w['year']->addTeacher($w['admin']);
        $w['group']->addTutor($w['admin']);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-scc-' . (++self::$counter));
        $student->addGroup($w['group']);
        $slot = (new \App\Entity\TimeSlot())->setAcademicYear($w['year'])->setDayOfWeek(1)->setStartTime(new \DateTimeImmutable('08:00'))->setEndTime(new \DateTimeImmutable('09:00'))->setName('1ª hora');
        $this->persist($student, $slot, new SetupStepConfirmation($w['year'], 'catalogues', $w['admin']));
    }
}
