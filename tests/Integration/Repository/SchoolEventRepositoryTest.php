<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\Teacher;
use App\Repository\SchoolEventRepository;
use App\Tests\Integration\RepositoryTestCase;

class SchoolEventRepositoryTest extends RepositoryTestCase
{
    private SchoolEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var SchoolEventRepository $repository */
        $repository       = self::getContainer()->get(SchoolEventRepository::class);
        $this->repository = $repository;
    }

    // ── Visibilidad general ─────────────────────────────────────────────────

    public function testFindVisibleForTeacherAndDateIncludesGeneralEvents(): void
    {
        $world   = $this->makeWorld('general');
        $viewer  = $this->makeTeacher('viewer');
        $event   = $this->makeEvent($world, general: true, name: 'Claustro');

        $result = $this->repository->findVisibleForTeacherAndDate($viewer, $world['year'], $event->getDate());

        self::assertCount(1, $result);
        self::assertSame('Claustro', $result[0]->getName());
    }

    public function testFindVisibleForTeacherAndDateIncludesEventsForTaughtGroup(): void
    {
        $world  = $this->makeWorld('taught');
        $viewer = $this->makeTeacher('taught.viewer');
        $world['group']->addTeacher($viewer, 'Matemáticas');
        $this->persist($viewer);

        $event = $this->makeEvent($world, general: false, name: 'Reunión de 1ºA', groups: [$world['group']]);

        $result = $this->repository->findVisibleForTeacherAndDate($viewer, $world['year'], $event->getDate());

        self::assertCount(1, $result);
        self::assertSame('Reunión de 1ºA', $result[0]->getName());
    }

    public function testFindVisibleForTeacherAndDateIncludesEventsForTutoredGroup(): void
    {
        $world  = $this->makeWorld('tutored');
        $viewer = $this->makeTeacher('tutored.viewer');
        $world['group']->addTutor($viewer);
        $this->persist($viewer);

        $event = $this->makeEvent($world, general: false, name: 'Reunión de tutoría', groups: [$world['group']]);

        $result = $this->repository->findVisibleForTeacherAndDate($viewer, $world['year'], $event->getDate());

        self::assertCount(1, $result);
        self::assertSame('Reunión de tutoría', $result[0]->getName());
    }

    public function testFindVisibleForTeacherAndDateExcludesUnrelatedRestrictedEvents(): void
    {
        $world  = $this->makeWorld('unrelated');
        $viewer = $this->makeTeacher('unrelated.viewer');
        $this->persist($viewer);

        $this->makeEvent($world, general: false, name: 'Reunión ajena', groups: [$world['group']]);

        $result = $this->repository->findVisibleForTeacherAndDate($viewer, $world['year'], new \DateTimeImmutable('2026-03-10'));

        self::assertCount(0, $result);
    }

    public function testFindAllForAcademicYearAndDateIncludesEverythingRegardlessOfVisibility(): void
    {
        $world = $this->makeWorld('all');
        $this->makeEvent($world, general: true, name: 'Evento general', date: new \DateTimeImmutable('2026-03-10'));
        $this->makeEvent($world, general: false, name: 'Evento restringido', groups: [$world['group']], date: new \DateTimeImmutable('2026-03-10'));

        $result = $this->repository->findAllForAcademicYearAndDate($world['year'], new \DateTimeImmutable('2026-03-10'));

        self::assertCount(2, $result);
    }

    // ── Listado paginado / filtros ───────────────────────────────────────────

    public function testCreateFilteredQuerySearchesNameAndDescription(): void
    {
        $world = $this->makeWorld('search');
        $this->makeEvent($world, general: true, name: 'Jornada de puertas abiertas', description: 'Presentación a nuevas familias');
        $this->makeEvent($world, general: true, name: 'Claustro extraordinario');

        /** @var list<SchoolEvent> $byName */
        $byName = $this->repository->createFilteredQuery($world['year'], 'puertas')->getResult();
        self::assertCount(1, $byName);
        self::assertSame('Jornada de puertas abiertas', $byName[0]->getName());

        /** @var list<SchoolEvent> $byDescription */
        $byDescription = $this->repository->createFilteredQuery($world['year'], 'nuevas familias')->getResult();
        self::assertCount(1, $byDescription);
        self::assertSame('Jornada de puertas abiertas', $byDescription[0]->getName());
    }

    public function testCreateFilteredQueryFiltersByGroup(): void
    {
        $world      = $this->makeWorld('groupfilter');
        $otherGroup = (new Group())->setName('1ºB')->setCourse($world['course']);
        $this->persist($otherGroup);

        $this->makeEvent($world, general: false, name: 'Reunión 1ºA', groups: [$world['group']]);
        $this->makeEvent($world, general: false, name: 'Reunión 1ºB', groups: [$otherGroup]);
        $this->makeEvent($world, general: true, name: 'General para todos');

        /** @var list<SchoolEvent> $result */
        $result = $this->repository->createFilteredQuery($world['year'], '', $world['group']->getId()->toRfc4122())->getResult();

        self::assertCount(1, $result);
        self::assertSame('Reunión 1ºA', $result[0]->getName());
    }

    public function testFindByAcademicYearAndIdReturnsNullForEventOfAnotherYear(): void
    {
        $world       = $this->makeWorld('scoped');
        $otherCentre = (new EducationalCentre())->setCode('41' . substr(md5('scoped-other'), 0, 6))->setName('IES otro')->setCity('Sevilla');
        $otherYear   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($otherCentre);
        $this->persist($otherCentre, $otherYear);

        $event = $this->makeEvent($world, general: true, name: 'Evento propio');

        self::assertNotNull($this->repository->findByAcademicYearAndId($world['year'], $event->getId()->toRfc4122()));
        self::assertNull($this->repository->findByAcademicYearAndId($otherYear, $event->getId()->toRfc4122()));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{centre: EducationalCentre, year: AcademicYear, course: Course, group: Group} */
    private function makeWorld(string $suffix): array
    {
        $centre = (new EducationalCentre())->setCode('41' . substr(md5($suffix . 'se'), 0, 6))->setName('IES ' . $suffix)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course = (new Course())->setName('DAW')->setAcademicYear($year);
        $group  = (new Group())->setName('1ºA')->setCourse($course);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group);

        return compact('centre', 'year', 'course', 'group');
    }

    private function makeTeacher(string $suffix): Teacher
    {
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername('teacher.ser.' . $suffix . uniqid('', false));
        $this->persist($teacher);

        return $teacher;
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, course: Course, group: Group} $world
     * @param list<Group> $groups
     */
    private function makeEvent(
        array $world,
        bool $general,
        string $name,
        string $description = '',
        array $groups = [],
        ?\DateTimeImmutable $date = null,
    ): SchoolEvent {
        $event = (new SchoolEvent())
            ->setAcademicYear($world['year'])
            ->setDate($date ?? new \DateTimeImmutable('2026-03-10'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName($name)
            ->setDescription($description !== '' ? $description : null)
            ->setGeneral($general);

        foreach ($groups as $group) {
            $event->addGroup($group);
        }

        $this->persist($event);

        return $event;
    }
}
