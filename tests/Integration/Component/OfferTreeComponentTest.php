<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\GroupTeacher;
use App\Entity\PersonName;
use App\Entity\Sanction;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use App\Entity\SanctionTask;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\GroupTeacherRepository;
use App\Service\SanctionTaskGenerator;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

class OfferTreeComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    // ── color del grupo ──────────────────────────────────────────────────────

    public function testSaveDetailStoresACustomGroupColor(): void
    {
        [$admin, $centre, , , $group] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->createLiveComponent('Admin:OfferTreeComponent', ['centre' => $centre], $this->client)
            ->call('selectGroup', ['id' => $group->getId()->toRfc4122()])
            ->set('customColor', true)
            ->set('editColor', '#AA11BB')
            ->call('saveDetail');

        $this->em->clear();
        self::assertSame('#aa11bb', $this->em->find(Group::class, $group->getId())?->getColor());
    }

    public function testTheColorPickerOnlyShowsWithTheCustomColorCheckboxAndStartsFromTheComputedColor(): void
    {
        [$admin, $centre, , , $group] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:OfferTreeComponent', ['centre' => $centre], $this->client)
            ->call('selectGroup', ['id' => $group->getId()->toRfc4122()]);
        $crawler = $component->render()->crawler();
        self::assertCount(1, $crawler->filter('#group-custom-color'));
        self::assertCount(0, $crawler->filter('#group-color'), 'sin la casilla, no hay selector');
        self::assertCount(0, $crawler->filter('#group-custom-color[checked]'));

        $crawler = $component->set('customColor', true)->render()->crawler();
        self::assertCount(1, $crawler->filter('#group-color'));
        $default = self::getContainer()->get(\App\Service\GroupColorPalette::class)->defaultHex($group->getId()->toRfc4122());
        self::assertSame($default, $crawler->filter('#group-color')->attr('value'), 'parte del color calculado');
    }

    public function testCheckingCustomColorWithoutTouchingThePickerStoresTheComputedColorAsStartingPoint(): void
    {
        [$admin, $centre, , , $group] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->createLiveComponent('Admin:OfferTreeComponent', ['centre' => $centre], $this->client)
            ->call('selectGroup', ['id' => $group->getId()->toRfc4122()])
            ->set('customColor', true)
            ->call('saveDetail');

        $this->em->clear();
        $default = self::getContainer()->get(\App\Service\GroupColorPalette::class)->defaultHex($group->getId()->toRfc4122());
        self::assertSame($default, $this->em->find(Group::class, $group->getId())?->getColor());
    }

    public function testSaveDetailWithoutTouchingTheColorKeepsTheComputedOne(): void
    {
        [$admin, $centre, , , $group] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->createLiveComponent('Admin:OfferTreeComponent', ['centre' => $centre], $this->client)
            ->call('selectGroup', ['id' => $group->getId()->toRfc4122()])
            ->call('saveDetail');

        $this->em->clear();
        self::assertNull($this->em->find(Group::class, $group->getId())?->getColor());
    }

    public function testSaveDetailRejectsAnInvalidColor(): void
    {
        [$admin, $centre, , , $group] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $this->createLiveComponent('Admin:OfferTreeComponent', ['centre' => $centre], $this->client)
            ->call('selectGroup', ['id' => $group->getId()->toRfc4122()])
            ->set('customColor', true)
            ->set('editColor', 'red;background:url(x)')
            ->call('saveDetail');

        $this->em->clear();
        self::assertNull($this->em->find(Group::class, $group->getId())?->getColor());
    }

    public function testUncheckingCustomColorGoesBackToTheComputedColor(): void
    {
        [$admin, $centre, , , $group] = $this->makeScenario();
        $group->setColor('#123456');
        $this->flush();
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:OfferTreeComponent', ['centre' => $centre], $this->client)
            ->call('selectGroup', ['id' => $group->getId()->toRfc4122()]);
        $crawler = $component->render()->crawler();
        self::assertCount(1, $crawler->filter('#group-custom-color[checked]'), 'un grupo con color propio llega con la casilla marcada');
        self::assertSame('#123456', $crawler->filter('#group-color')->attr('value'));

        $component->set('customColor', false)->call('saveDetail');

        $this->em->clear();
        self::assertNull($this->em->find(Group::class, $group->getId())?->getColor());
    }

    // ── addGroupTeacher ──────────────────────────────────────────────────────

    public function testAddGroupTeacherCreatesAssignment(): void
    {
        [$admin, $centre, $year, , $group, $teacher] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:OfferTreeComponent', ['centre' => $centre], $this->client);
        $component
            ->set('groupId', $group->getId()->toRfc4122())
            ->set('newTeacherId', $teacher->getId()->toRfc4122())
            ->set('newTeacherSubject', 'Física')
            ->call('addGroupTeacher');

        $this->em->clear();
        /** @var GroupTeacherRepository $groupTeachers */
        $groupTeachers = self::getContainer()->get(GroupTeacherRepository::class);
        $assignments   = $groupTeachers->findByTeacherAndAcademicYearOrdered(
            $this->em->find(Teacher::class, $teacher->getId()),
            $this->em->find(AcademicYear::class, $year->getId()),
        );

        self::assertCount(1, $assignments);
        self::assertSame('Física', $assignments[0]->getSubject());
        self::assertSame($group->getId()->toRfc4122(), $assignments[0]->getGroup()->getId()->toRfc4122());
    }

    public function testAddGroupTeacherRejectsDuplicate(): void
    {
        [$admin, $centre, $year, , $group, $teacher] = $this->makeScenario();
        $group->addTeacher($teacher, 'Matemáticas');
        $this->flush();
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:OfferTreeComponent', ['centre' => $centre], $this->client);
        $component
            ->set('groupId', $group->getId()->toRfc4122())
            ->set('newTeacherId', $teacher->getId()->toRfc4122())
            ->set('newTeacherSubject', 'Matemáticas')
            ->call('addGroupTeacher');

        $this->em->clear();
        /** @var GroupTeacherRepository $groupTeachers */
        $groupTeachers = self::getContainer()->get(GroupTeacherRepository::class);
        $assignments   = $groupTeachers->findByTeacherAndAcademicYearOrdered(
            $this->em->find(Teacher::class, $teacher->getId()),
            $this->em->find(AcademicYear::class, $year->getId()),
        );
        self::assertCount(1, $assignments);
    }

    public function testAddGroupTeacherRequiresSubject(): void
    {
        [$admin, $centre, $year, , $group, $teacher] = $this->makeScenario();
        $this->loginAs($admin, $centre);

        $component = $this->createLiveComponent('Admin:OfferTreeComponent', ['centre' => $centre], $this->client);
        $component
            ->set('groupId', $group->getId()->toRfc4122())
            ->set('newTeacherId', $teacher->getId()->toRfc4122())
            ->set('newTeacherSubject', '')
            ->call('addGroupTeacher');

        $this->em->clear();
        /** @var GroupTeacherRepository $groupTeachers */
        $groupTeachers = self::getContainer()->get(GroupTeacherRepository::class);
        $assignments   = $groupTeachers->findByTeacherAndAcademicYearOrdered(
            $this->em->find(Teacher::class, $teacher->getId()),
            $this->em->find(AcademicYear::class, $year->getId()),
        );
        self::assertCount(0, $assignments);
    }

    // ── removeGroupTeacher con tareas de sanción ─────────────────────────────

    public function testRemoveGroupTeacherAsksForConfirmationWhenItHasSanctionTasksThenDeletesThem(): void
    {
        [$admin, $centre, $year, , $group, $teacher] = $this->makeScenario();
        $group->addTeacher($teacher, 'Matemáticas');
        $assignment = $group->getTeacherAssignments()->first();
        self::assertInstanceOf(GroupTeacher::class, $assignment);
        $this->flush();
        $this->generateSanctionTasks($centre, $year, $group, $teacher);
        $this->loginAs($admin, $centre);
        $id = $assignment->getId()->toRfc4122();

        $component = $this->createLiveComponent('Admin:OfferTreeComponent', ['centre' => $centre], $this->client);
        $component->set('groupId', $group->getId()->toRfc4122())->call('removeGroupTeacher', ['id' => $id]);

        // Todavía no se ha borrado nada: avisa y espera confirmación.
        $this->em->clear();
        self::assertNotNull($this->em->find(GroupTeacher::class, $assignment->getId()));
        self::assertStringContainsString('tarea de sanción asociada', $component->render()->crawler()->text());

        $component->call('confirmRemoveGroupTeacher', ['id' => $id]);

        $this->em->clear();
        self::assertNull($this->em->find(GroupTeacher::class, $assignment->getId()));
        self::assertCount(0, $this->em->getRepository(SanctionTask::class)->findAll());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateSanctionTasks(EducationalCentre $centre, AcademicYear $year, Group $group, Teacher $teacher): void
    {
        $student  = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-otc-1');
        $category = (new SanctionMeasureCategory())->setEducationalCentre($centre)->setName('Correcciones')->setPosition(0);
        $measure  = (new SanctionMeasure())
            ->setEducationalCentre($centre)->setCategory($category)->setName('Expulsión con actividades')
            ->setHasDateRange(true)->setPosition(0)->setActive(true);
        $sanction = (new Sanction())
            ->setAcademicYear($year)->setStudent($student)->setGroup($group)->setRegisteredBy($teacher)
            ->setDetails('Detalles de prueba')->setNoMeasureApplied(false)
            ->setEffectiveFrom(new \DateTimeImmutable('+2 days'))->setEffectiveTo(new \DateTimeImmutable('+7 days'));
        $sanction->addMeasure($measure);
        $this->persist($student, $category, $measure, $sanction);

        /** @var SanctionTaskGenerator $generator */
        $generator = self::getContainer()->get(SanctionTaskGenerator::class);
        self::assertCount(1, $generator->generateFor($sanction));
        $this->flush();
    }

    /** @return array{0: Teacher, 1: EducationalCentre, 2: AcademicYear, 3: Course, 4: Group, 5: Teacher} */
    private function makeScenario(): array
    {
        $admin   = (new Teacher(new PersonName('Admin', 'User')))->setUsername('admin.otc')->setAdmin(true);
        $centre  = (new EducationalCentre())->setCode('41000003')->setName('IES Oferta Test')->setCity('Sevilla');
        $year    = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $course  = (new Course())->setName('DAW')->setAcademicYear($year);
        $group   = (new Group())->setName('1ºA')->setCourse($course);
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.otc');

        $this->persist($admin, $centre, $year, $course, $group, $teacher);
        $centre->setActiveAcademicYear($year);
        $year->addTeacher($teacher);
        $this->flush();

        return [$admin, $centre, $year, $course, $group, $teacher];
    }
}
