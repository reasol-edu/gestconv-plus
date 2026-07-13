<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\IncidentReportObservation;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\Sanction;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;

class NotificationControllerTest extends ControllerTestCase
{
    private int $nextReportNumber = 0;

    // ── index (pending queue) ────────────────────────────────────────────────

    public function testIndexListsPendingReportsAndSanctions(): void
    {
        [$teacher, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->persist($report);
        $sanction = $this->makeSanction($student, $group, $teacher);
        $this->persist($sanction);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/notificaciones');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $student->getName()->getLastName());
    }

    public function testIndexExcludesAlreadyNotifiedItems(): void
    {
        [$teacher, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->persist($report);
        $this->notifyReport($report, $method, $teacher);
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/notificaciones');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No hay partes pendientes de notificar.');
    }

    public function testIndexWithoutCentreRedirectsToSelection(): void
    {
        $teacher = $this->makeTeacher('no.centre.notif');
        $this->persist($teacher);
        $this->loginAs($teacher);

        $this->client->request('GET', '/notificaciones');

        self::assertResponseRedirects();
        self::assertStringContainsString('/centro', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // ── index (tabs) ─────────────────────────────────────────────────────────

    public function testIndexDefaultsToPendingTab(): void
    {
        [$teacher, $centre] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/notificaciones');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav[aria-label="Secciones de notificaciones"] [aria-current="page"]');
        self::assertSame(
            'Notificaciones pendientes',
            trim($crawler->filter('nav[aria-label="Secciones de notificaciones"] [aria-current="page"]')->text()),
        );
    }

    public function testIndexHistoryTabRendersHistoryComponent(): void
    {
        [$teacher, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $teacher, $behavior);
        $this->persist($report);
        $this->notifyReport($report, $method, $teacher);
        $this->loginAs($teacher, $centre);

        $crawler = $this->client->request('GET', '/notificaciones?tab=history');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Historial de notificaciones',
            trim($crawler->filter('nav[aria-label="Secciones de notificaciones"] [aria-current="page"]')->text()),
        );
        self::assertSelectorTextContains('body', $student->getName()->getLastName());
        self::assertSelectorTextNotContains('body', 'No hay partes pendientes de notificar.');
    }

    public function testIndexHistoryTabShowsAllCommunicationsToAdmin(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $this->notifyReport($report, $method, $creator);

        $admin = $this->makeTeacher('admin.notif.history')->setAdmin(true);
        $this->persist($admin);
        $this->loginAs($admin, $centre);

        $crawler = $this->client->request('GET', '/notificaciones?tab=history');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $student->getName()->getLastName());
    }

    // ── registerForReport ────────────────────────────────────────────────────

    public function testRegisterForReportGetIsDeniedToUnrelatedTeacher(): void
    {
        [$creator, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $outsider = $this->makeTeacher('outsider.report');
        $this->persist($outsider);
        $this->loginAs($outsider, $centre);

        $this->client->request('GET', '/notificaciones/partes/' . $report->getId()->toRfc4122() . '/registrar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRegisterForReportGetRendersFormForCreator(): void
    {
        [$creator, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $this->loginAs($creator, $centre);

        $this->client->request('GET', '/notificaciones/partes/' . $report->getId()->toRfc4122() . '/registrar');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="method_id"]');
    }

    public function testRegisterForReportShowsContactDataToGroupTutor(): void
    {
        [$creator, $centre, $group, $student, $behavior] = $this->makeScenario();
        $tutor = $this->makeTeacher('tutor.register.report');
        $group->addTutor($tutor);
        $group->addStudent($student);
        $student->setTutorName1('María Tutora');
        $this->persist($tutor);
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $this->loginAs($tutor, $centre);

        $this->client->request('GET', '/notificaciones/partes/' . $report->getId()->toRfc4122() . '/registrar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Datos de contacto');
        self::assertSelectorTextContains('body', 'María Tutora');
    }

    public function testRegisterForReportShowsContactDataToRegisteringTeacher(): void
    {
        [$creator, $centre, $group, $student, $behavior] = $this->makeScenario();
        $student->setTutorName1('María Tutora');
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $this->loginAs($creator, $centre);

        $this->client->request('GET', '/notificaciones/partes/' . $report->getId()->toRfc4122() . '/registrar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Datos de contacto');
        self::assertSelectorTextContains('body', 'María Tutora');
    }

    public function testRegisterForReportShowsReportDetailsAndObservations(): void
    {
        [$creator, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $observation = new IncidentReportObservation($report, $creator, new \DateTimeImmutable(), '<p>Seguimiento inicial.</p>');
        $this->persist($observation);
        $this->loginAs($creator, $centre);

        $this->client->request('GET', '/notificaciones/partes/' . $report->getId()->toRfc4122() . '/registrar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $behavior->getName());
        self::assertSelectorTextContains('body', 'Seguimiento inicial.');
        self::assertSelectorTextContains('body', $creator->getName()->getLastName());
    }

    public function testRegisterForReportPostWithNotifiedResultSetsNotifiedCommunication(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $this->loginAs($creator, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/notificaciones/partes/' . $reportId . '/registrar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/partes/' . $reportId . '/registrar', [
            '_token'       => $token,
            'method_id'    => $method->getId()->toRfc4122(),
            'performed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'result'       => 'notified',
            'description'  => 'Llamada realizada a la familia.',
        ]);

        self::assertResponseRedirects('/notificaciones');

        $this->em->clear();
        $updated = $this->em->find(IncidentReport::class, $report->getId());
        self::assertNotNull($updated);
        self::assertTrue($updated->isNotified());
    }

    public function testRegisterForReportPostWithNotNotifiedResultDoesNotSetNotifiedCommunication(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $this->loginAs($creator, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/notificaciones/partes/' . $reportId . '/registrar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/partes/' . $reportId . '/registrar', [
            '_token'       => $token,
            'method_id'    => $method->getId()->toRfc4122(),
            'performed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'result'       => 'not_notified',
            'description'  => 'La familia no responde.',
        ]);

        self::assertResponseRedirects('/notificaciones');

        $this->em->clear();
        $updated = $this->em->find(IncidentReport::class, $report->getId());
        self::assertNotNull($updated);
        self::assertFalse($updated->isNotified());
    }

    public function testRegisterForReportPostWithMissingFieldsShowsError(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $this->loginAs($creator, $centre);

        $reportId = $report->getId()->toRfc4122();
        $crawler  = $this->client->request('GET', '/notificaciones/partes/' . $reportId . '/registrar');
        $token    = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/partes/' . $reportId . '/registrar', [
            '_token'       => $token,
            'method_id'    => '',
            'performed_at' => '',
            'result'       => 'notified',
        ]);

        self::assertResponseRedirects('/notificaciones/partes/' . $reportId . '/registrar');

        $this->em->clear();
        $updated = $this->em->find(IncidentReport::class, $report->getId());
        self::assertNotNull($updated);
        self::assertFalse($updated->isNotified());
    }

    // ── registerForSanction ──────────────────────────────────────────────────

    public function testRegisterForSanctionGetIsDeniedToUnrelatedTeacher(): void
    {
        [$creator, $centre, $group, $student] = $this->makeScenario();
        $sanction = $this->makeSanction($student, $group, $creator);
        $this->persist($sanction);
        $outsider = $this->makeTeacher('outsider.sanction');
        $this->persist($outsider);
        $this->loginAs($outsider, $centre);

        $this->client->request('GET', '/notificaciones/sanciones/' . $sanction->getId()->toRfc4122() . '/registrar');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRegisterForSanctionShowsContactDataToGroupTutor(): void
    {
        [$creator, $centre, $group, $student] = $this->makeScenario();
        $tutor = $this->makeTeacher('tutor.register.sanction');
        $group->addTutor($tutor);
        $group->addStudent($student);
        $student->setTutorName1('María Tutora');
        $this->persist($tutor);
        $sanction = $this->makeSanction($student, $group, $creator);
        $this->persist($sanction);
        $this->loginAs($tutor, $centre);

        $this->client->request('GET', '/notificaciones/sanciones/' . $sanction->getId()->toRfc4122() . '/registrar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Datos de contacto');
        self::assertSelectorTextContains('body', 'María Tutora');
    }

    public function testRegisterForSanctionShowsContactDataToRegisteringTeacher(): void
    {
        [$creator, $centre, $group, $student] = $this->makeScenario();
        $student->setTutorName1('María Tutora');
        $sanction = $this->makeSanction($student, $group, $creator);
        $this->persist($sanction);
        $this->loginAs($creator, $centre);

        $this->client->request('GET', '/notificaciones/sanciones/' . $sanction->getId()->toRfc4122() . '/registrar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Datos de contacto');
        self::assertSelectorTextContains('body', 'María Tutora');
    }

    public function testRegisterForSanctionPostWithNotifiedResultSetsNotifiedCommunication(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $sanction = $this->makeSanction($student, $group, $creator);
        $this->persist($sanction);
        $this->loginAs($creator, $centre);

        $sanctionId = $sanction->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/notificaciones/sanciones/' . $sanctionId . '/registrar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/sanciones/' . $sanctionId . '/registrar', [
            '_token'       => $token,
            'method_id'    => $method->getId()->toRfc4122(),
            'performed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'result'       => 'notified',
            'description'  => 'Comunicada por correo.',
        ]);

        self::assertResponseRedirects('/notificaciones');

        $this->em->clear();
        $updated = $this->em->find(Sanction::class, $sanction->getId());
        self::assertNotNull($updated);
        self::assertTrue($updated->isNotified());
    }

    public function testRegisterForSanctionPostWithNotNotifiedResultDoesNotSetNotifiedCommunication(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $sanction = $this->makeSanction($student, $group, $creator);
        $this->persist($sanction);
        $this->loginAs($creator, $centre);

        $sanctionId = $sanction->getId()->toRfc4122();
        $crawler    = $this->client->request('GET', '/notificaciones/sanciones/' . $sanctionId . '/registrar');
        $token      = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/sanciones/' . $sanctionId . '/registrar', [
            '_token'       => $token,
            'method_id'    => $method->getId()->toRfc4122(),
            'performed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'result'       => 'not_notified',
        ]);

        self::assertResponseRedirects('/notificaciones');

        $this->em->clear();
        $updated = $this->em->find(Sanction::class, $sanction->getId());
        self::assertNotNull($updated);
        self::assertFalse($updated->isNotified());
    }

    // ── registerForStudentReports (bulk notify) ─────────────────────────────

    public function testRegisterForStudentReportsGetRedirectsWhenNoNotifiableReports(): void
    {
        [$teacher, $centre, $group, $student] = $this->makeScenario();
        $this->loginAs($teacher, $centre);

        $this->client->request('GET', '/notificaciones/partes/estudiante/' . $student->getId()->toRfc4122() . '/registrar');

        self::assertResponseRedirects('/notificaciones');
    }

    public function testRegisterForStudentReportsGetRendersChecklistWithAllReportsCheckedByDefault(): void
    {
        [$creator, $centre, $group, $student, $behavior] = $this->makeScenario();
        $report1 = $this->makeReport($student, $group, $creator, $behavior);
        $report2 = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report1, $report2);
        $this->loginAs($creator, $centre);

        $crawler = $this->client->request('GET', '/notificaciones/partes/estudiante/' . $student->getId()->toRfc4122() . '/registrar');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('input[name="report_ids[]"]'));
        self::assertCount(2, $crawler->filter('input[name="report_ids[]"][checked]'));
        self::assertSelectorTextContains('body', $behavior->getName());
    }

    public function testRegisterForStudentReportsPostRegistersAllSelectedReports(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report1 = $this->makeReport($student, $group, $creator, $behavior);
        $report2 = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report1, $report2);
        $this->loginAs($creator, $centre);

        $studentId = $student->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/notificaciones/partes/estudiante/' . $studentId . '/registrar');
        $token     = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/partes/estudiante/' . $studentId . '/registrar', [
            '_token'       => $token,
            'report_ids'   => [$report1->getId()->toRfc4122(), $report2->getId()->toRfc4122()],
            'method_id'    => $method->getId()->toRfc4122(),
            'performed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'result'       => 'notified',
            'description'  => 'Llamada conjunta a la familia.',
        ]);

        self::assertResponseRedirects('/notificaciones');

        $this->em->clear();
        $updated1 = $this->em->find(IncidentReport::class, $report1->getId());
        $updated2 = $this->em->find(IncidentReport::class, $report2->getId());
        self::assertTrue($updated1->isNotified());
        self::assertTrue($updated2->isNotified());
    }

    public function testRegisterForStudentReportsPostWithoutSelectionShowsError(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $report = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($report);
        $this->loginAs($creator, $centre);

        $studentId = $student->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/notificaciones/partes/estudiante/' . $studentId . '/registrar');
        $token     = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/partes/estudiante/' . $studentId . '/registrar', [
            '_token'       => $token,
            'method_id'    => $method->getId()->toRfc4122(),
            'performed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'result'       => 'notified',
        ]);

        self::assertResponseRedirects('/notificaciones/partes/estudiante/' . $studentId . '/registrar');

        $this->em->clear();
        $updated = $this->em->find(IncidentReport::class, $report->getId());
        self::assertFalse($updated->isNotified());
    }

    public function testRegisterForStudentReportsPostIgnoresReportOutsideNotifierScope(): void
    {
        [$creator, $centre, $group, $student, $behavior, $method] = $this->makeScenario();
        $this->setReportNotifierSetting($centre, 'report_teacher');

        $viewer = $this->makeTeacher('viewer.bulk.scope');
        $this->persist($viewer);

        $ownReport   = $this->makeReport($student, $group, $viewer, $behavior);
        $otherReport = $this->makeReport($student, $group, $creator, $behavior);
        $this->persist($ownReport, $otherReport);
        $this->loginAs($viewer, $centre);

        $studentId = $student->getId()->toRfc4122();
        $crawler   = $this->client->request('GET', '/notificaciones/partes/estudiante/' . $studentId . '/registrar');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="report_ids[]"]'));

        $token = $crawler->filter('[name="_token"]')->first()->attr('value');

        $this->client->request('POST', '/notificaciones/partes/estudiante/' . $studentId . '/registrar', [
            '_token'       => $token,
            'report_ids'   => [$ownReport->getId()->toRfc4122(), $otherReport->getId()->toRfc4122()],
            'method_id'    => $method->getId()->toRfc4122(),
            'performed_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            'result'       => 'notified',
        ]);

        self::assertResponseRedirects('/notificaciones');

        $this->em->clear();
        $updatedOwn   = $this->em->find(IncidentReport::class, $ownReport->getId());
        $updatedOther = $this->em->find(IncidentReport::class, $otherReport->getId());
        self::assertTrue($updatedOwn->isNotified());
        self::assertFalse($updatedOther->isNotified());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function setReportNotifierSetting(EducationalCentre $centre, string $value): void
    {
        $definition = (new SettingDefinition())
            ->setKey('notifications.report_notifier')
            ->setType(SettingType::Choice)
            ->setDefaultValue('both')
            ->setGlobalScope(true)
            ->setCentreScope(true)
            ->setChoices('report_teacher,group_tutor,both');
        $this->persist($definition);

        $centreValue = (new CentreSettingValue())
            ->setDefinition($definition)
            ->setCentre($centre)
            ->setValue($value);
        $this->persist($centreValue);
    }

    /**
     * @return array{0: Teacher, 1: EducationalCentre, 2: Group, 3: Student, 4: IncidentBehavior, 5: CommunicationMethod}
     */
    private function makeScenario(): array
    {
        $teacher   = $this->makeTeacher('teacher.' . uniqid('', false));
        $centre    = (new EducationalCentre())->setCode('41' . substr(uniqid('', false), 0, 6))->setName('IES Test')->setCity('Sevilla');
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
        $method = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);

        $centre->setActiveAcademicYear($year);
        $this->persist($teacher, $centre, $year, $course, $group, $student, $category, $behavior, $method);

        return [$teacher, $centre, $group, $student, $behavior, $method];
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

        return $report;
    }

    private function makeSanction(Student $student, Group $group, Teacher $creator): Sanction
    {
        $academicYear = $group->getCourse()->getAcademicYear();

        return (new Sanction())
            ->setAcademicYear($academicYear)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setDetails('Detalle de la sanción de prueba.')
            ->setNoMeasureApplied(false);
    }

    private function notifyReport(IncidentReport $report, CommunicationMethod $method, Teacher $teacher): void
    {
        $communication = Communication::forIncidentReport($report, $method, $teacher, new \DateTimeImmutable(), CommunicationResult::Notified);
        $this->persist($communication);

        $report->setNotifiedCommunication($communication);
        $this->flush();
    }
}
