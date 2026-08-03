<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use App\Entity\IncidentReport;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Service\SanctionTaskGenerator;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

class NotificationBellComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    public function testBellIncludesOwnPendingSanctionTask(): void
    {
        [$centre, $year, $course, $group, $teacher] = $this->makeScenario('task');
        $group->addTeacher($teacher, 'Matemáticas');
        $this->flush();

        $sanction = $this->makeSanction($year, $group, $teacher, 'Ana', 'García', requiresDates: true);
        $this->generateTasksFor($sanction);
        $this->loginAs($teacher, $centre);

        $render = $this->createLiveComponent('NotificationBellComponent', [], $this->client)->render();

        self::assertStringContainsString('1', $render->crawler()->filter('button span')->text());
        self::assertStringContainsString(
            'Tarea de sanción de Ana García pendiente de cumplimentar',
            $render->crawler()->text(),
        );
    }

    public function testBellExcludesCompletedSanctionTask(): void
    {
        [$centre, $year, $course, $group, $teacher] = $this->makeScenario('done');
        $group->addTeacher($teacher, 'Matemáticas');
        $this->flush();

        $sanction = $this->makeSanction($year, $group, $teacher, 'Ana', 'García', requiresDates: true);
        $tasks    = $this->generateTasksFor($sanction);
        foreach ($tasks as $task) {
            $task->setCompletedAt(new \DateTimeImmutable());
        }
        $this->flush();
        $this->loginAs($teacher, $centre);

        $render = $this->createLiveComponent('NotificationBellComponent', [], $this->client)->render();

        self::assertCount(0, $render->crawler()->filter('button span'));
        self::assertStringContainsString('No tienes tareas pendientes', $render->crawler()->text());
    }

    public function testBellOrdersTaskAlongsideReportByDate(): void
    {
        [$centre, $year, $course, $group, $teacher] = $this->makeScenario('order');
        $group->addTeacher($teacher, 'Matemáticas');
        $this->flush();

        $sanction = $this->makeSanction($year, $group, $teacher, 'Ana', 'García', requiresDates: true);
        $sanction->setEffectiveFrom(new \DateTimeImmutable('+30 days'));
        $this->generateTasksFor($sanction);

        $report = $this->makeReport($year, $group, $teacher, 'Bruno', 'Ruiz');
        $report->setOccurredAt(new \DateTimeImmutable('+1 day'));
        $this->persist($report);
        $this->loginAs($teacher, $centre);

        $render = $this->createLiveComponent('NotificationBellComponent', [], $this->client)->render();

        $labels = $render->crawler()->filter('ul li p.text-sm')->each(static fn ($node) => trim($node->text()));

        self::assertCount(2, $labels);
        self::assertStringContainsString('Parte de Bruno Ruiz pendiente de notificar', $labels[0]);
        self::assertStringContainsString('Tarea de sanción de Ana García pendiente de cumplimentar', $labels[1]);
    }

    public function testBellIncludesNoteThresholdForTutor(): void
    {
        [$centre, $year, $course, $group, $teacher] = $this->makeScenario('threshold');
        $group->addTutor($teacher);
        $this->flush();

        $type = (new DailyNoteType())->setEducationalCentre($centre)->setName('Retraso a primera hora')->setOccurrencesForReport(2)->setPosition(0);
        $this->persist($type);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-nbc-threshold');
        $student->addGroup($group);
        $this->persist($student);

        $note1 = (new DailyNote())->setAcademicYear($year)->setStudent($student)->setGroup($group)->setType($type)->setRegisteredBy($teacher);
        $note2 = (new DailyNote())->setAcademicYear($year)->setStudent($student)->setGroup($group)->setType($type)->setRegisteredBy($teacher);
        $this->persist($note1, $note2);

        $this->loginAs($teacher, $centre);

        $render = $this->createLiveComponent('NotificationBellComponent', [], $this->client)->render();

        self::assertStringContainsString(
            'Ana García ha alcanzado 2 notas de «Retraso a primera hora»',
            $render->crawler()->text(),
        );
    }

    public function testBellExcludesNoteThresholdForUnrelatedTeacher(): void
    {
        [$centre, $year, $course, $group, $teacher] = $this->makeScenario('threshold-unrelated');
        $other = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.nbc.unrelated' . uniqid('', false));
        $this->persist($other);
        $this->flush();

        $type = (new DailyNoteType())->setEducationalCentre($centre)->setName('Retraso a primera hora')->setOccurrencesForReport(1)->setPosition(0);
        $this->persist($type);
        $student = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-nbc-threshold-unrelated');
        $student->addGroup($group);
        $this->persist($student);

        $note = (new DailyNote())->setAcademicYear($year)->setStudent($student)->setGroup($group)->setType($type)->setRegisteredBy($other);
        $this->persist($note);

        $this->loginAs($other, $centre);

        $render = $this->createLiveComponent('NotificationBellComponent', [], $this->client)->render();

        self::assertStringContainsString('No tienes tareas pendientes', $render->crawler()->text());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{0: EducationalCentre, 1: AcademicYear, 2: Course, 3: Group, 4: Teacher} */
    private function makeScenario(string $suffix): array
    {
        $centre  = (new EducationalCentre())->setCode('41000' . substr(md5($suffix . 'nbc'), 0, 3))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA' . $suffix)->setCourse($course);
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.nbc.' . $suffix . uniqid('', false));

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $teacher);

        return [$centre, $year, $course, $group, $teacher];
    }

    private function makeSanction(AcademicYear $year, Group $group, Teacher $creator, string $firstName, string $lastName, bool $requiresDates): Sanction
    {
        $student = (new Student(new PersonName($firstName, $lastName)))->setStudentId('NIE-nbc-' . uniqid('', false));

        $sanction = (new Sanction())
            ->setAcademicYear($year)
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setDetails('Detalles de prueba')
            ->setNoMeasureApplied(!$requiresDates)
            ->setNoMeasureReason($requiresDates ? null : 'Sin medida')
            ->setEffectiveFrom($requiresDates ? new \DateTimeImmutable('+2 days') : null)
            ->setEffectiveTo($requiresDates ? new \DateTimeImmutable('+7 days') : null);

        if ($requiresDates) {
            $category = (new SanctionMeasureCategory())
                ->setEducationalCentre($group->getCourse()->getAcademicYear()->getEducationalCentre())
                ->setName('Correcciones')
                ->setPosition(0);
            $measure = (new SanctionMeasure())
                ->setEducationalCentre($group->getCourse()->getAcademicYear()->getEducationalCentre())
                ->setCategory($category)
                ->setName('Expulsión con actividades')
                ->setHasDateRange(true)
                ->setPosition(0)
                ->setActive(true);
            $this->persist($category, $measure);
            $sanction->addMeasure($measure);
        }

        $this->persist($student, $sanction);

        return $sanction;
    }

    private function makeReport(AcademicYear $year, Group $group, Teacher $creator, string $firstName, string $lastName): IncidentReport
    {
        $centre   = $year->getEducationalCentre();
        $student  = (new Student(new PersonName($firstName, $lastName)))->setStudentId('NIE-nbc-r-' . uniqid('', false));
        $category = (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName('Contrarias')
            ->setSerious(false)
            ->setPosition(0);
        $behavior = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Perturbación del normal desarrollo de las actividades')
            ->setPosition(0)
            ->setActive(true);
        $report = (new IncidentReport())
            ->setAcademicYear($year)
            ->setNumber(random_int(1, 1_000_000))
            ->setStudent($student)
            ->setGroup($group)
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test.</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($behavior);
        $this->persist($student, $category, $behavior);

        return $report;
    }

    /** @return list<\App\Entity\SanctionTask> */
    private function generateTasksFor(Sanction $sanction): array
    {
        /** @var SanctionTaskGenerator $generator */
        $generator = self::getContainer()->get(SanctionTaskGenerator::class);
        $tasks     = $generator->generateFor($sanction);
        self::assertNotSame([], $tasks);

        return $tasks;
    }
}
