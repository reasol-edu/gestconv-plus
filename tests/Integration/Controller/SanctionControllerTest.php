<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\GlobalSettingValue;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\GroupTeacher;
use App\Entity\Sanction;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Entity\SanctionTask;
use App\Entity\SettingDefinition;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class SanctionControllerTest extends ControllerTestCase
{
    private int $nextReportNumber = 0;
    private static int $scenarioCounter = 0;

    // ── index ─────────────────────────────────────────────────────────────────

    public function testIndexIsAccessibleToAnyTeacher(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/sanciones');

        self::assertResponseIsSuccessful();
    }

    public function testIndexPendingTasksOnlyQueryParamPreChecksFilterAndFiltersList(): void
    {
        [$admin, $centre, $group, $student] = $this->makeScenario();
        $teacher = $this->makeTeacher('pending.tasks.query');
        $this->persist($teacher);
        $group->addTeacher($teacher, 'Matemáticas');
        $this->flush();
        $groupTeacher = $group->getTeacherAssignments()->first();
        $withPendingTask = $this->makeSanction($admin, $student, $group);
        $this->persist(new SanctionTask($withPendingTask, $groupTeacher));
        $withoutTasks = $this->makeSanction($admin, $student, $group);
        $this->flush();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/sanciones?pendingTasksOnly=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button[data-live-action-param="togglePendingTasksOnly"].border-forest-300');
        self::assertSelectorExists('a[href$="/sanciones/' . $withPendingTask->getId()->toRfc4122() . '"]');
        self::assertCount(0, $crawler->filter('a[href$="/sanciones/' . $withoutTasks->getId()->toRfc4122() . '"]'));
    }

    public function testIndexShowsTaskRatioAndPendingTasksFilterToGroupTutor(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $tutor = $this->makeTeacher('tutor.tasks.visibility');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $subjectTeacher = $this->makeTeacher('subject.tasks.visibility');
        $this->persist($subjectTeacher);
        $group->addTeacher($subjectTeacher, 'Matemáticas');
        $this->flush();
        $groupTeacher = $group->getTeacherAssignments()->first();
        $report = $this->makeReport($student, $group, $behavior, $admin);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->persist(new SanctionTask($sanction, $groupTeacher));
        $this->flush();
        $this->loginAs($tutor, $centre);

        $crawler = $this->client->request('GET', '/sanciones');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('a[href$="/sanciones/' . $sanction->getId()->toRfc4122() . '"]')->closest('tr');
        self::assertNotNull($row);
        self::assertStringContainsString('0/1', $row->filter('td[data-label="Tareas"]')->text());
        self::assertStringContainsString('bg-amber-50/60', $row->attr('class') ?? '');

        $filteredCrawler = $this->client->request('GET', '/sanciones?pendingTasksOnly=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href$="/sanciones/' . $sanction->getId()->toRfc4122() . '"]');
        self::assertCount(1, $filteredCrawler->filter('a[href$="/sanciones/' . $sanction->getId()->toRfc4122() . '"]'));
    }

    public function testIndexShowsTaskCompletionRatioAndHighlightsIncompleteSanctions(): void
    {
        [$admin, $centre, $group, $student] = $this->makeScenario();
        $teacher1 = $this->makeTeacher('task.ratio.1');
        $teacher2 = $this->makeTeacher('task.ratio.2');
        $this->persist($teacher1, $teacher2);
        $group->addTeacher($teacher1, 'Matemáticas');
        $group->addTeacher($teacher2, 'Lengua');
        $this->flush();
        [$groupTeacher1, $groupTeacher2] = $group->getTeacherAssignments()->toArray();

        $partial = $this->makeSanction($admin, $student, $group);
        $completedTask = new SanctionTask($partial, $groupTeacher1);
        $completedTask->setCompletedAt(new \DateTimeImmutable());
        $this->persist($completedTask, new SanctionTask($partial, $groupTeacher2));

        $withoutTasks = $this->makeSanction($admin, $student, $group);
        $this->flush();

        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/sanciones');

        self::assertResponseIsSuccessful();

        $partialRow = $crawler->filter('a[href$="/sanciones/' . $partial->getId()->toRfc4122() . '"]')->closest('tr');
        self::assertNotNull($partialRow);
        self::assertStringContainsString('1/2', $partialRow->filter('td[data-label="Tareas"]')->text());
        self::assertStringContainsString('bg-amber-50/60', (string) $partialRow->attr('class'));

        $withoutTasksRow = $crawler->filter('a[href$="/sanciones/' . $withoutTasks->getId()->toRfc4122() . '"]')->closest('tr');
        self::assertNotNull($withoutTasksRow);
        self::assertStringNotContainsString('bg-amber-50/60', (string) $withoutTasksRow->attr('class'));
        self::assertSame('', trim($withoutTasksRow->filter('td[data-label="Tareas"]')->text()));
    }

    public function testIndexRedirectsUnauthenticated(): void
    {
        $this->client->request('GET', '/sanciones');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testIndexRedirectsWhenNoCentreSelected(): void
    {
        $teacher = $this->makeTeacher('no.centre.index');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/sanciones');

        self::assertResponseRedirects();
        self::assertStringContainsString('/centro', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // ── new: paso 1 (selección de alumno) ─────────────────────────────────────

    public function testNewStepOneIsAccessibleToCentreAdmin(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/nueva');

        self::assertResponseIsSuccessful();
    }

    public function testNewStepOneIsDeniedToRegularTeacher(): void
    {
        [, $centre, , , ,] = $this->makeScenario();
        $regular           = $this->makeTeacher('regular.teacher');
        $this->persist($regular);
        $this->loginAs($regular, $centre);

        $this->client->request('GET', '/sanciones/nueva');

        self::assertResponseStatusCodeSame(403);
    }

    // ── new: paso 2 (formulario) ──────────────────────────────────────────────

    public function testNewStepTwoRendersFormForValidStudentAndGroup(): void
    {
        [$admin, $centre, $group, $student] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $url = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
             . '&groupId=' . $group->getId()->toRfc4122();
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testNewStepTwoRedirectsForUnknownStudent(): void
    {
        [$admin, $centre, $group] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $url = '/sanciones/nueva?studentId=00000000-0000-0000-0000-000000000000'
             . '&groupId=' . $group->getId()->toRfc4122();
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/sanciones/nueva');
    }

    public function testNewStepTwoRedirectsForGroupOfAnotherCentre(): void
    {
        [$admin, $centre, , $student] = $this->makeScenario();
        [, , $otherGroup] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $url = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
             . '&groupId=' . $otherGroup->getId()->toRfc4122();
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/sanciones/nueva');
    }

    // ── new: POST crea sanción ────────────────────────────────────────────────

    public function testNewPostCreatesSanctionWithMeasure(): void
    {
        [$admin, $centre, $group, $student, $behavior, $measure] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $behavior);
        $this->loginAs($admin, $centre);

        $url     = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
                 . '&groupId=' . $group->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $url);
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'   => $token,
            'reports'  => [$report->getId()->toRfc4122()],
            'measures' => [$measure->getId()->toRfc4122()],
            'details'  => 'Motivo de la sanción.',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $sanctions = $this->em->getRepository(Sanction::class)->findAll();
        self::assertCount(1, $sanctions);
        self::assertSame($student->getId()->toRfc4122(), $sanctions[0]->getStudent()->getId()->toRfc4122());
    }

    public function testNewPostCreatesSanctionWithNoMeasureApplied(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $behavior);
        $this->loginAs($admin, $centre);

        $url     = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
                 . '&groupId=' . $group->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $url);
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'            => $token,
            'reports'           => [$report->getId()->toRfc4122()],
            'no_measure_applied' => '1',
            'no_measure_reason' => 'Pendiente de resolución.',
            'details'           => 'Detalles de la sanción.',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $sanctions = $this->em->getRepository(Sanction::class)->findAll();
        self::assertCount(1, $sanctions);
        self::assertTrue($sanctions[0]->isNoMeasureApplied());
    }

    public function testNewPostShowsErrorWhenNoReportSelected(): void
    {
        [$admin, $centre, $group, $student, , $measure] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $url     = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
                 . '&groupId=' . $group->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $url);
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'   => $token,
            'reports'  => [],
            'measures' => [$measure->getId()->toRfc4122()],
            'details'  => 'Detalles.',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'parte');
    }

    public function testNewPostShowsErrorWhenNoMeasureAndNoMeasureNotChecked(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $behavior);
        $this->loginAs($admin, $centre);

        $url     = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
                 . '&groupId=' . $group->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $url);
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'  => $token,
            'reports' => [$report->getId()->toRfc4122()],
            'details' => 'Detalles.',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'medida');
    }

    // ── new: fechas no lectivas ────────────────────────────────────────────────

    public function testNewPostRejectsNonWorkingEffectiveFromAndEffectiveToDates(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report          = $this->makeReport($student, $group, $behavior);
        $dateRangeMeasure = $this->makeDateRangeMeasure($centre);
        $this->loginAs($admin, $centre);

        $saturday = (new \DateTimeImmutable('next saturday'))->format('Y-m-d');
        $sunday   = (new \DateTimeImmutable('next saturday'))->modify('+1 day')->format('Y-m-d');

        $url     = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
                 . '&groupId=' . $group->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $url);
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'         => $token,
            'reports'        => [$report->getId()->toRfc4122()],
            'measures'       => [$dateRangeMeasure->getId()->toRfc4122()],
            'details'        => 'Motivo de la sanción.',
            'effective_from' => $saturday,
            'effective_to'   => $sunday,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'no puede ser un día no lectivo');

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Sanction::class)->findAll());
    }

    public function testNewPostCreatesSanctionWithValidSchoolDayDateRange(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report          = $this->makeReport($student, $group, $behavior);
        $dateRangeMeasure = $this->makeDateRangeMeasure($centre);
        $this->loginAs($admin, $centre);

        $monday  = (new \DateTimeImmutable('next monday'))->format('Y-m-d');
        $tuesday = (new \DateTimeImmutable('next monday'))->modify('+1 day')->format('Y-m-d');

        $url     = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
                 . '&groupId=' . $group->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $url);
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'         => $token,
            'reports'        => [$report->getId()->toRfc4122()],
            'measures'       => [$dateRangeMeasure->getId()->toRfc4122()],
            'details'        => 'Motivo de la sanción.',
            'effective_from' => $monday,
            'effective_to'   => $tuesday,
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Sanction::class)->findAll());
    }

    public function testNewPostShowsErrorWhenDetailsEmpty(): void
    {
        [$admin, $centre, $group, $student, $behavior, $measure] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $behavior);
        $this->loginAs($admin, $centre);

        $url     = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
                 . '&groupId=' . $group->getId()->toRfc4122();
        $crawler = $this->client->request('GET', $url);
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', $url, [
            '_token'   => $token,
            'reports'  => [$report->getId()->toRfc4122()],
            'measures' => [$measure->getId()->toRfc4122()],
            'details'  => '',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testNewPostWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $group, $student] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $url = '/sanciones/nueva?studentId=' . $student->getId()->toRfc4122()
             . '&groupId=' . $group->getId()->toRfc4122();
        $this->client->request('POST', $url, [
            '_token'  => 'invalid-token',
            'details' => 'Test.',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function testShowIsAccessibleToCentreAdmin(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testShowDisplaysNotifyButtonWhenPendingAndAuthorized(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        // Un enlace es la pastilla de estado y el otro el botón de notificar añadido junto a Editar/Eliminar.
        self::assertSame(2, $crawler->filter('a[href="/notificaciones/sanciones/' . $sanction->getId()->toRfc4122() . '/registrar"]')->count());
    }

    public function testShowHidesNotifyButtonWhenAlreadyNotified(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $method   = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $communication = Communication::forSanction(
            $sanction, $method, $admin, new \DateTimeImmutable(), CommunicationResult::Notified,
        );
        $this->persist($method, $communication);
        $sanction->setNotifiedCommunication($communication);
        $this->flush();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('a[href="/notificaciones/sanciones/' . $sanction->getId()->toRfc4122() . '/registrar"]')->count());
    }

    public function testShowIsDeniedToUnrelatedTeacher(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $other    = $this->makeTeacher('unrelated.show');
        $this->persist($other);
        $this->loginAs($other, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(403);
    }

    public function testPdfIsAccessibleToCentreAdminAndReturnsAPdfDocument(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    public function testPdfUsesCustomFooterSetting(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);

        $defs = $this->em->getRepository(SettingDefinition::class);
        $this->persist(
            (new GlobalSettingValue())
                ->setDefinition($defs->findOneBy(['key' => 'reports.sanction_footer']))
                ->setValue('<p>Generado en {city} el {current_date}</p>'),
        );

        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    public function testPdfIsDeniedToUnrelatedTeacher(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $other    = $this->makeTeacher('unrelated.pdf');
        $this->persist($other);
        $this->loginAs($other, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/pdf');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPdfStillGeneratesOnceSanctionIsNotified(): void
    {
        // Once notified to the family, the PDF drops the "draft" watermark
        // (see PdfRenderer::render's $draftWatermark param); this just guards
        // that the render itself keeps working on that code path.
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $method   = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $communication = Communication::forSanction(
            $sanction, $method, $admin, new \DateTimeImmutable(), CommunicationResult::Notified,
        );
        $this->persist($method, $communication);
        $sanction->setNotifiedCommunication($communication);
        $this->flush();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/pdf');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    public function testShowIsAccessibleToReportCreator(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $creator  = $this->makeTeacher('report.creator.show');
        $this->persist($creator);
        $report   = $this->makeReport($student, $group, $behavior, $creator);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($creator, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
    }

    public function testShowDisplaysTasksBlockAndCalendarLabelToGroupTutor(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $tutor = $this->makeTeacher('tutor.show.tasks');
        $this->persist($tutor);
        $group->addTutor($tutor);
        $subjectTeacher = $this->makeTeacher('subject.show.tasks');
        $this->persist($subjectTeacher);
        $group->addTeacher($subjectTeacher, 'Matemáticas');
        $this->flush();
        $groupTeacher = $group->getTeacherAssignments()->first();
        $report   = $this->makeReport($student, $group, $behavior, $admin);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $sanction->setCalendarLabel('Aula de convivencia');
        $this->persist(new SanctionTask($sanction, $groupTeacher));
        $this->flush();
        $this->loginAs($tutor, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aula de convivencia');
        self::assertSelectorTextContains('body', 'Matemáticas');
        self::assertSelectorTextContains('body', 'Pendiente');
    }

    public function testShowDisplaysCommunicationHistory(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $method   = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);
        $communication = Communication::forSanction(
            $sanction, $method, $admin, new \DateTimeImmutable(), CommunicationResult::Notified, 'Habló con el padre.',
        );
        $this->persist($method, $communication);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Llamada telefónica');
        self::assertSelectorTextContains('body', 'Habló con el padre.');
    }

    public function testShowWithoutCommunicationsDisplaysEmptyHistory(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Todavía no se ha registrado ninguna comunicación.');
    }

    public function testShowReturns404ForNonExistentSanction(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('GET', '/sanciones/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    // ── edit ──────────────────────────────────────────────────────────────────

    public function testEditGetIsAccessibleToCentreAdmin(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/editar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
        self::assertStringContainsString($behavior->getName(), $crawler->text());
    }

    public function testEditGetIsDeniedToReportCreator(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $creator  = $this->makeTeacher('creator.edit.denied');
        $this->persist($creator);
        $report   = $this->makeReport($student, $group, $behavior, $creator);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($creator, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/editar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEditPostSavesChanges(): void
    {
        [$admin, $centre, $group, $student, $behavior, $measure] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $sanctionId = $sanction->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/sanciones/' . $sanctionId . '/editar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/sanciones/' . $sanctionId . '/editar', [
            '_token'   => $token,
            'reports'  => [$report->getId()->toRfc4122()],
            'measures' => [$measure->getId()->toRfc4122()],
            'details'  => 'Descripción actualizada.',
        ]);

        self::assertResponseRedirects('/sanciones/' . $sanctionId);

        $this->em->clear();
        $updated = $this->em->find(Sanction::class, $sanction->getId());
        self::assertNotNull($updated);
        self::assertSame('Descripción actualizada.', $updated->getDetails());
    }

    public function testEditPostRejectsNonWorkingEffectiveDates(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report          = $this->makeReport($student, $group, $behavior);
        $dateRangeMeasure = $this->makeDateRangeMeasure($centre);
        $sanction        = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $saturday = (new \DateTimeImmutable('next saturday'))->format('Y-m-d');
        $sunday   = (new \DateTimeImmutable('next saturday'))->modify('+1 day')->format('Y-m-d');

        $sanctionId = $sanction->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/sanciones/' . $sanctionId . '/editar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/sanciones/' . $sanctionId . '/editar', [
            '_token'         => $token,
            'reports'        => [$report->getId()->toRfc4122()],
            'measures'       => [$dateRangeMeasure->getId()->toRfc4122()],
            'details'        => 'Descripción actualizada.',
            'effective_from' => $saturday,
            'effective_to'   => $sunday,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'no puede ser un día no lectivo');

        $this->em->clear();
        $unchanged = $this->em->find(Sanction::class, $sanction->getId());
        self::assertNotNull($unchanged);
        self::assertNull($unchanged->getEffectiveFrom());
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteIsDeniedToReportCreator(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $creator  = $this->makeTeacher('creator.delete.denied');
        $this->persist($creator);
        $report   = $this->makeReport($student, $group, $behavior, $creator);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($creator, $centre);

        $this->client->request('POST', '/sanciones/' . $sanction->getId()->toRfc4122() . '/eliminar', [
            '_token' => 'any-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteIsGrantedToCentreAdmin(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $sanctionId = $sanction->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/sanciones/' . $sanctionId);
        $token      = $crawler->filter('form[action$="/eliminar"] [name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/sanciones/' . $sanctionId . '/eliminar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/sanciones');

        $this->em->clear();
        self::assertNull($this->em->find(Sanction::class, $sanction->getId()));
    }

    public function testDeleteReturns404ForNonExistentSanction(): void
    {
        [$admin, $centre] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->client->request('POST', '/sanciones/00000000-0000-0000-0000-000000000000/eliminar', [
            '_token' => 'any-token',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── refrescar materias ───────────────────────────────────────────────────

    public function testRefreshTasksPreviewShowsNothingToRefreshWhenUpToDate(): void
    {
        [$admin, $centre, $group, $student] = $this->makeScenario();
        $sanction = $this->makeSanction($admin, $student, $group);
        $teacher  = $this->makeTeacher('subject.uptodate');
        $this->persist($teacher);
        $group->addTeacher($teacher, 'Matemáticas');
        $this->flush();
        $groupTeacher = $group->getTeacherAssignments()->first();
        $this->persist(new SanctionTask($sanction, $groupTeacher));
        $this->flush();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/tareas/refrescar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Las materias de esta sanción ya están al día');
        self::assertCount(0, $crawler->filter('button[type="submit"]'));
    }

    public function testRefreshTasksPreviewListsSubjectsToAddAndStaleTaskWithoutData(): void
    {
        [$admin, $centre, $group, $student] = $this->makeScenario();
        $otherGroup   = $this->makeSecondGroup($group);
        $sanction     = $this->makeSanction($admin, $student, $group);
        $newTeacher   = $this->makeTeacher('subject.new');
        $staleTeacher = $this->makeTeacher('subject.stale');
        $this->persist($newTeacher, $staleTeacher);
        $group->addTeacher($newTeacher, 'Matemáticas');
        $staleGroupTeacher = new GroupTeacher($otherGroup, $staleTeacher, 'Física');
        $this->persist($staleGroupTeacher);
        $this->flush();
        $this->persist(new SanctionTask($sanction, $staleGroupTeacher));
        $this->flush();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/tareas/refrescar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Matemáticas');
        self::assertSelectorTextContains('body', 'Física');
        self::assertSelectorTextNotContains('body', 'ya tiene trabajo asignado');
        self::assertCount(1, $crawler->filter('button[type="submit"]'));
    }

    public function testRefreshTasksPreviewWarnsWhenStaleTaskHasData(): void
    {
        [$admin, $centre, $group, $student] = $this->makeScenario();
        $otherGroup   = $this->makeSecondGroup($group);
        $sanction     = $this->makeSanction($admin, $student, $group);
        $staleTeacher = $this->makeTeacher('subject.stale.data');
        $this->persist($staleTeacher);
        $staleGroupTeacher = new GroupTeacher($otherGroup, $staleTeacher, 'Física');
        $this->persist($staleGroupTeacher);
        $task = new SanctionTask($sanction, $staleGroupTeacher);
        $task->setDescription('<p>Trabajo entregado.</p>')->setCompletedAt(new \DateTimeImmutable());
        $this->persist($task);
        $this->flush();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/tareas/refrescar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'ya tiene trabajo asignado');
    }

    public function testRefreshTasksPreviewIsDeniedToUnrelatedTeacher(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $other    = $this->makeTeacher('unrelated.refresh.preview');
        $this->persist($other);
        $this->loginAs($other, $centre);

        $this->client->request('GET', '/sanciones/' . $sanction->getId()->toRfc4122() . '/tareas/refrescar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRefreshTasksConfirmAppliesDiff(): void
    {
        [$admin, $centre, $group, $student] = $this->makeScenario();
        $otherGroup   = $this->makeSecondGroup($group);
        $sanction     = $this->makeSanction($admin, $student, $group);
        $newTeacher   = $this->makeTeacher('subject.confirm.new');
        $staleTeacher = $this->makeTeacher('subject.confirm.stale');
        $this->persist($newTeacher, $staleTeacher);
        $group->addTeacher($newTeacher, 'Matemáticas');
        $staleGroupTeacher = new GroupTeacher($otherGroup, $staleTeacher, 'Física');
        $this->persist($staleGroupTeacher);
        $this->flush();
        $staleTask = new SanctionTask($sanction, $staleGroupTeacher);
        $this->persist($staleTask);
        $this->flush();
        $sanctionId = $sanction->getId()->toRfc4122();
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/sanciones/' . $sanctionId . '/tareas/refrescar');
        $token   = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/sanciones/' . $sanctionId . '/tareas/refrescar', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/sanciones/' . $sanctionId);

        $this->em->clear();
        /** @var Sanction $reloaded */
        $reloaded   = $this->em->getRepository(Sanction::class)->find($sanctionId);
        $subjects   = array_map(
            static fn (SanctionTask $t): string => $t->getGroupTeacher()->getSubject(),
            $reloaded->getTasks()->toArray(),
        );
        self::assertSame(['Matemáticas'], $subjects);
    }

    public function testRefreshTasksConfirmIsDeniedToUnrelatedTeacher(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $other    = $this->makeTeacher('unrelated.refresh.confirm');
        $this->persist($other);
        $this->loginAs($other, $centre);

        $this->client->request('POST', '/sanciones/' . $sanction->getId()->toRfc4122() . '/tareas/refrescar', [
            '_token' => 'any-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRefreshTasksConfirmWithInvalidCsrfIsDenied(): void
    {
        [$admin, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report   = $this->makeReport($student, $group, $behavior);
        $sanction = $this->makeSanction($admin, $student, $group, [$report]);
        $this->loginAs($admin, $centre);

        $this->client->request('POST', '/sanciones/' . $sanction->getId()->toRfc4122() . '/tareas/refrescar', [
            '_token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student, 4: IncidentBehavior, 5: SanctionMeasure}
     */
    private function makeScenario(): array
    {
        $suffix    = (string) ++self::$scenarioCounter;
        $admin     = $this->makeTeacher('cadmin.' . uniqid('', false) . $suffix);
        $centre    = (new EducationalCentre())->setCode('4' . str_pad($suffix, 7, '0', STR_PAD_LEFT))->setName('IES Test')->setCity('Sevilla');
        $year      = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course    = (new Course())->setName('DAW')->setAcademicYear($year);
        $group     = (new Group())->setName('1ºA')->setCourse($course);
        $student   = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-' . uniqid('', false));
        $category  = (new IncidentBehaviorCategory())
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
        $measureCat = (new SanctionMeasureCategory())
            ->setEducationalCentre($centre)
            ->setName('Correcciones')
            ->setPosition(0);
        $measure = (new SanctionMeasure())
            ->setEducationalCentre($centre)
            ->setCategory($measureCat)
            ->setName('Amonestación oral')
            ->setHasDateRange(false)
            ->setPosition(0)
            ->setActive(true);

        $centre->setActiveAcademicYear($year);
        $centre->addAdmin($admin);
        $group->addStudent($student);
        $this->persist($admin, $centre, $year, $course, $group, $student, $category, $behavior, $measureCat, $measure);

        return [$admin, $centre, $group, $student, $behavior, $measure];
    }

    private function makeDateRangeMeasure(EducationalCentre $centre): SanctionMeasure
    {
        $category = (new SanctionMeasureCategory())
            ->setEducationalCentre($centre)
            ->setName('Con fechas')
            ->setPosition(1);
        $measure = (new SanctionMeasure())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Cambio de centro')
            ->setHasDateRange(true)
            ->setPosition(0)
            ->setActive(true);
        $this->persist($category, $measure);

        return $measure;
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }

    private function makeSecondGroup(Group $group): Group
    {
        $otherGroup = (new Group())->setName('1ºB')->setCourse($group->getCourse());
        $this->persist($otherGroup);

        return $otherGroup;
    }

    private function makeReport(
        Student $student,
        Group $group,
        IncidentBehavior $behavior,
        ?Teacher $creator = null,
    ): IncidentReport {
        if ($creator === null) {
            $creator = $this->makeTeacher('default.creator.' . uniqid('', false));
            $this->persist($creator);
        }

        $academicYear = $group->getCourse()->getAcademicYear();

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

    /**
     * @param list<IncidentReport> $reports
     */
    private function makeSanction(
        Teacher $registeredBy,
        Student $student,
        Group $group,
        array $reports = [],
    ): Sanction {
        $academicYear = $group->getCourse()->getAcademicYear();

        $sanction = (new Sanction())
            ->setAcademicYear($academicYear)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($registeredBy)
            ->setDetails('Detalles de prueba.')
            ->setNoMeasureApplied(false);

        $this->persist($sanction);

        foreach ($reports as $report) {
            $report->setSanction($sanction);
        }
        $this->flush();

        return $sanction;
    }
}
