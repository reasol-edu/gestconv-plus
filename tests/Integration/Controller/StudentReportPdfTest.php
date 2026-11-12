<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AcademicYear;
use App\Entity\CentreSettingValue;
use App\Entity\Course;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Student;
use App\Entity\StudentExit;
use App\Entity\Teacher;
use App\Service\StudentConductReportBuilder;
use App\Tests\Integration\ControllerTestCase;
use Twig\Environment;

/** Informe de convivencia del estudiante en PDF (ficha del estudiante). */
class StudentReportPdfTest extends ControllerTestCase
{
    private static int $counter = 0;
    private int $reportNumber = 0;

    // ── la ruta ───────────────────────────────────────────────────────────────

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $w = $this->world();

        $this->client->request('GET', '/alumnado/' . $w['student']->getId()->toRfc4122() . '/informe');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testCentreAdminGetsAPdfNamedAfterTheStudent(): void
    {
        $w = $this->world();
        $this->makeReport($w, $w['author']);
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', '/alumnado/' . $w['student']->getId()->toRfc4122() . '/informe');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('informe-convivencia-ana-garcia', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());    }

    public function testAStudentOfAnotherCentreIsNotFound(): void
    {
        $w       = $this->world();
        $foreign = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', '/alumnado/' . $foreign['student']->getId()->toRfc4122() . '/informe');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheStudentSheetOffersTheReportForm(): void
    {
        $w = $this->world();
        $this->loginAs($w['admin'], $w['centre']);

        $this->client->request('GET', '/alumnado/' . $w['student']->getId()->toRfc4122());

        self::assertSelectorExists('form[action$="/informe"] input[name="from"]');
        self::assertSelectorExists('form[action$="/informe"] input[name="to"]');
    }

    // ── lo que contiene: nunca más de lo que la ficha enseña ──────────────────

    public function testAnUnrelatedTeacherOnlyGetsWhatTheyRegisteredThemselves(): void
    {
        $w = $this->world();
        $this->makeReport($w, $w['author']);
        $stranger = $this->teacher('stranger');
        $this->persist($stranger);

        $data = $this->builder()->build($stranger, $w['centre'], $w['student'], $w['year']);

        self::assertSame([], $data->reports);
        self::assertSame([], $data->sanctions);
        self::assertFalse($data->related, 'No debe dar a entender que el estudiante no tiene partes.');
        self::assertStringContainsString('solo incluye lo que tú has registrado', $this->render($stranger, $w, $data));
        self::assertStringNotContainsString('Perturbación', $this->render($stranger, $w, $data));
    }

    public function testTheAuthorSeesTheirOwnReportButNotAnotherTeachersReportOnTheSameStudent(): void
    {
        $w = $this->world();
        $mine   = $this->makeReport($w, $w['author']);
        $theirs = $this->makeReport($w, $this->teacher('other'));

        $data = $this->builder()->build($w['author'], $w['centre'], $w['student'], $w['year']);

        self::assertCount(1, $data->reports);
        self::assertSame($mine->getId()->toRfc4122(), $data->reports[0]->getId()->toRfc4122());
        self::assertNotSame($theirs->getId()->toRfc4122(), $data->reports[0]->getId()->toRfc4122());
    }

    public function testTheTutorSeesAllReportsAndTheFamilyContactButAnUnrelatedTeacherNeverSeesTheContact(): void
    {
        $w = $this->world();
        $w['student']->setTutorName1('Marta Tutora Legal')->setTutorEmail1('marta@example.org')->setContactPhone1('600111222');
        $w['group']->addTutor($w['tutor']);
        $this->makeReport($w, $w['author']);
        $stranger = $this->teacher('stranger');
        $this->persist($stranger);
        $this->flush();

        $tutorData = $this->builder()->build($w['tutor'], $w['centre'], $w['student'], $w['year']);
        self::assertTrue($tutorData->showContact);
        self::assertCount(1, $tutorData->reports);
        $tutorHtml = $this->render($w['tutor'], $w, $tutorData);
        self::assertStringContainsString('Marta Tutora Legal', $tutorHtml);
        self::assertStringContainsString('600111222', $tutorHtml);

        $strangerData = $this->builder()->build($stranger, $w['centre'], $w['student'], $w['year']);
        self::assertFalse($strangerData->showContact);
        $strangerHtml = $this->render($stranger, $w, $strangerData);
        self::assertStringNotContainsString('Marta Tutora Legal', $strangerHtml);
        self::assertStringNotContainsString('marta@example.org', $strangerHtml);
        self::assertStringNotContainsString('600111222', $strangerHtml);
    }

    public function testASanctionIsIncludedForTheCentreAdminButNotForAnUnrelatedTeacher(): void
    {
        $w      = $this->world();
        $report = $this->makeReport($w, $w['author']);
        $sanction = (new Sanction())
            ->setAcademicYear($w['year'])->setStudent($w['student'])->setGroup($w['group'])->setRegisteredBy($w['admin'])
            ->setDetails('Detalle.')->setNoMeasureApplied(true);
        $this->persist($sanction);
        $report->setSanction($sanction);
        $stranger = $this->teacher('stranger');
        $this->persist($stranger);
        $this->flush();

        self::assertCount(1, $this->builder()->build($w['admin'], $w['centre'], $w['student'], $w['year'])->sanctions);
        self::assertCount(0, $this->builder()->build($stranger, $w['centre'], $w['student'], $w['year'])->sanctions);
    }

    public function testTheDateRangeNarrowsReportsNotesAndExits(): void
    {
        $w   = $this->world();
        $old = $this->makeReport($w, $w['author']);
        $old->setOccurredAt(new \DateTimeImmutable('-60 days'));
        $recent = $this->makeReport($w, $w['author']);
        $type   = (new DailyNoteType())->setEducationalCentre($w['centre'])->setName('Retraso')->setPosition(0);
        $this->persist($type);
        $oldNote = (new DailyNote())->setAcademicYear($w['year'])->setStudent($w['student'])->setGroup($w['group'])->setType($type)->setRegisteredBy($w['author']);
        $oldNote->setOccurredAt(new \DateTimeImmutable('-60 days'));
        $newNote = (new DailyNote())->setAcademicYear($w['year'])->setStudent($w['student'])->setGroup($w['group'])->setType($type)->setRegisteredBy($w['author']);
        $reason  = (new ExitReason())->setEducationalCentre($w['centre'])->setName('Servicio')->setPosition(0);
        $this->persist($oldNote, $newNote, $reason);
        $oldExit = (new StudentExit())->setAcademicYear($w['year'])->setStudent($w['student'])->setGroup($w['group'])->setReason($reason)->setAuthorizedBy($w['author'])
            ->setExitedAt(new \DateTimeImmutable('-60 days'));
        $newExit = (new StudentExit())->setAcademicYear($w['year'])->setStudent($w['student'])->setGroup($w['group'])->setReason($reason)->setAuthorizedBy($w['author']);
        $this->persist($oldExit, $newExit);

        $all = $this->builder()->build($w['admin'], $w['centre'], $w['student'], $w['year']);
        self::assertCount(2, $all->reports);
        self::assertCount(2, $all->notes);
        self::assertCount(2, $all->exits);

        $from   = new \DateTimeImmutable('-30 days');
        $ranged = $this->builder()->build($w['admin'], $w['centre'], $w['student'], $w['year'], $from, null);
        self::assertCount(1, $ranged->reports);
        self::assertSame($recent->getId()->toRfc4122(), $ranged->reports[0]->getId()->toRfc4122());
        self::assertCount(1, $ranged->notes);
        self::assertCount(1, $ranged->exits);

        $upTo = $this->builder()->build($w['admin'], $w['centre'], $w['student'], $w['year'], null, new \DateTimeImmutable('-30 days'));
        self::assertCount(1, $upTo->reports);
        self::assertSame($old->getId()->toRfc4122(), $upTo->reports[0]->getId()->toRfc4122());
    }

    public function testNotesAndExitsAreOmittedWhenTheirSectionsAreDisabled(): void
    {
        $w = $this->world();
        foreach (['daily_notes', 'exits'] as $feature) {
            $definition = (new SettingDefinition())->setKey('features.' . $feature)->setType(SettingType::Boolean)
                ->setCategory('settings.category.features')->setDefaultValue('true')->setGlobalScope(true)->setCentreScope(true);
            $this->persist($definition, (new CentreSettingValue())->setDefinition($definition)->setCentre($w['centre'])->setValue('false'));
        }

        $data = $this->builder()->build($w['admin'], $w['centre'], $w['student'], $w['year']);

        self::assertFalse($data->includesNotes);
        self::assertFalse($data->includesExits);
        $html = $this->render($w['admin'], $w, $data);
        self::assertStringNotContainsString('Notas del día', $html);
        self::assertStringNotContainsString('Salidas del aula', $html);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function builder(): StudentConductReportBuilder
    {
        /** @var StudentConductReportBuilder $builder */
        $builder = self::getContainer()->get(StudentConductReportBuilder::class);

        return $builder;
    }

    /** @param array<string, mixed> $w */
    private function render(Teacher $viewer, array $w, \App\Service\StudentConductReportData $data): string
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->render('pdf/student_report.html.twig', [
            'centre'         => $w['centre'],
            'data'           => $data,
            'viewer'         => $viewer,
            'pdfGeneratedAt' => new \DateTimeImmutable(),
        ]);
    }

    private function teacher(string $name): Teacher
    {
        return (new Teacher(new PersonName('Test', ucfirst($name))))->setUsername($name . '.' . uniqid('', false))->setPassword('x');
    }

    /** @param array<string, mixed> $w */
    private function makeReport(array $w, Teacher $by): IncidentReport
    {
        $this->persist($by);
        $report = (new IncidentReport())
            ->setAcademicYear($w['year'])
            ->setNumber(++$this->reportNumber)
            ->setStudent($w['student'])
            ->setGroup($w['group'])
            ->setRegisteredBy($by)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Descripción del hecho.</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($w['behavior']);
        $this->persist($report);

        return $report;
    }

    /**
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, admin: Teacher,
     *               author: Teacher, tutor: Teacher, behavior: IncidentBehavior}
     */
    private function world(): array
    {
        $n       = ++self::$counter;
        $centre  = (new EducationalCentre())->setCode('7' . str_pad((string) $n, 7, '0', STR_PAD_LEFT))->setName('IES Informe ' . $n)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-R-' . $n);
        $student->addGroup($group);
        $admin   = $this->teacher('admin');
        $author  = $this->teacher('author');
        $tutor   = $this->teacher('tutor');
        $category = (new IncidentBehaviorCategory())->setEducationalCentre($centre)->setName('Contrarias')->setSerious(false)->setPosition(0);
        $behavior = (new IncidentBehavior())->setEducationalCentre($centre)->setCategory($category)->setName('Perturbación del normal desarrollo')->setPosition(0)->setActive(true);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student, $admin, $author, $tutor, $category, $behavior);
        $centre->addAdmin($admin);
        $this->flush();

        return compact('centre', 'year', 'group', 'student', 'admin', 'author', 'tutor', 'behavior');
    }
}
