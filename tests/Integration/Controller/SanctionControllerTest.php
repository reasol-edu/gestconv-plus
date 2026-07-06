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
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Sanction;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
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
        $programme = (new Programme())->setName('DAW')->setAcademicYear($year);
        $level     = (new ProgrammeYear())->setName('1º')->setProgramme($programme);
        $group     = (new Group())->setName('1ºA')->setProgrammeYear($level);
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
        $this->persist($admin, $centre, $year, $programme, $level, $group, $student, $category, $behavior, $measureCat, $measure);

        return [$admin, $centre, $group, $student, $behavior, $measure];
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
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

    /**
     * @param list<IncidentReport> $reports
     */
    private function makeSanction(
        Teacher $registeredBy,
        Student $student,
        Group $group,
        array $reports = [],
    ): Sanction {
        $academicYear = $group->getProgrammeYear()->getProgramme()->getAcademicYear();

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
