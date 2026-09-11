<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\GroupTeacherRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

class OfferTreeComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

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

    // ── Helpers ───────────────────────────────────────────────────────────────

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
