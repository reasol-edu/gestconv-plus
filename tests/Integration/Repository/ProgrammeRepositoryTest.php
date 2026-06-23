<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Teacher;
use App\Repository\ProgrammeRepository;
use App\Tests\Integration\RepositoryTestCase;

class ProgrammeRepositoryTest extends RepositoryTestCase
{
    private ProgrammeRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ProgrammeRepository $repo */
        $repo       = self::getContainer()->get(ProgrammeRepository::class);
        $this->repo = $repo;
    }

    // ── findByAcademicYearVisibleToTeacher ────────────────────────────────────

    public function testVisibleToTeacherReturnsProgrammeWhenGroupTutor(): void
    {
        $centre  = $this->makeCentre('41001002');
        $year    = $this->makeYear($centre);
        $teacher = $this->makeTeacher('tutor.1');
        $prog    = $this->makeProgramme($year, 'DAM');
        $other   = $this->makeProgramme($year, 'DAW');
        $level   = (new ProgrammeYear())->setName('1º')->setProgramme($prog);
        $group   = (new Group())->setName('DAM1A')->setProgrammeYear($level)->addTutor($teacher);
        $this->persist($centre, $year, $teacher, $prog, $other, $level, $group);

        $results = $this->repo->findByAcademicYearVisibleToTeacher($year, $teacher);

        self::assertCount(1, $results);
        self::assertSame('DAM', $results[0]->getName());
    }

    public function testVisibleToTeacherReturnsProgrammeWhenGroupTeacher(): void
    {
        $centre  = $this->makeCentre('41001003');
        $year    = $this->makeYear($centre);
        $teacher = $this->makeTeacher('grp.teacher.1');
        $prog    = $this->makeProgramme($year, 'DAM');
        $other   = $this->makeProgramme($year, 'DAW');
        $level   = (new ProgrammeYear())->setName('1º')->setProgramme($prog);
        $group   = (new Group())->setName('DAM1A')->setProgrammeYear($level);
        $this->persist($centre, $year, $teacher, $prog, $other, $level, $group);
        $group->addTeacher($teacher);
        $this->flush();

        $results = $this->repo->findByAcademicYearVisibleToTeacher($year, $teacher);

        self::assertCount(1, $results);
        self::assertSame('DAM', $results[0]->getName());
    }

    public function testVisibleToTeacherReturnsEmptyForUnrelatedTeacher(): void
    {
        $centre  = $this->makeCentre('41001004');
        $year    = $this->makeYear($centre);
        $teacher = $this->makeTeacher('unrelated.1');
        $prog    = $this->makeProgramme($year, 'DAM');
        $this->persist($centre, $year, $teacher, $prog);

        $results = $this->repo->findByAcademicYearVisibleToTeacher($year, $teacher);

        self::assertCount(0, $results);
    }

    public function testVisibleToTeacherDeduplicatesWhenMultipleGroupsInSameProgramme(): void
    {
        $centre  = $this->makeCentre('41001005');
        $year    = $this->makeYear($centre);
        $teacher = $this->makeTeacher('multi.group.1');
        $prog    = $this->makeProgramme($year, 'DAM');
        $level   = (new ProgrammeYear())->setName('1º')->setProgramme($prog);
        $groupA  = (new Group())->setName('DAM1A')->setProgrammeYear($level)->addTutor($teacher);
        $groupB  = (new Group())->setName('DAM1B')->setProgrammeYear($level)->addTutor($teacher);
        $this->persist($centre, $year, $teacher, $prog, $level, $groupA, $groupB);

        $results = $this->repo->findByAcademicYearVisibleToTeacher($year, $teacher);

        self::assertCount(1, $results);
    }

    public function testVisibleToTeacherReturnsProgrammeWhenOneOfMultipleTutors(): void
    {
        $centre  = $this->makeCentre('41001009');
        $year    = $this->makeYear($centre);
        $tutorA  = $this->makeTeacher('multi.tutor.a');
        $tutorB  = $this->makeTeacher('multi.tutor.b');
        $prog    = $this->makeProgramme($year, 'DAM');
        $level   = (new ProgrammeYear())->setName('1º')->setProgramme($prog);
        $group   = (new Group())->setName('DAM1A')->setProgrammeYear($level);
        $this->persist($centre, $year, $tutorA, $tutorB, $prog, $level, $group);
        $group->addTutor($tutorA);
        $group->addTutor($tutorB);
        $this->flush();

        $resultsA = $this->repo->findByAcademicYearVisibleToTeacher($year, $tutorA);
        $resultsB = $this->repo->findByAcademicYearVisibleToTeacher($year, $tutorB);

        self::assertCount(1, $resultsA);
        self::assertSame('DAM', $resultsA[0]->getName());
        self::assertCount(1, $resultsB);
        self::assertSame('DAM', $resultsB[0]->getName());
    }

    // ── findByAcademicYearOrdered ─────────────────────────────────────────────

    public function testFindByAcademicYearOrderedReturnsAllProgrammesOrderedByName(): void
    {
        $centre = $this->makeCentre('41000010');
        $year   = $this->makeYear($centre);
        $p1     = $this->makeProgramme($year, 'SMR');
        $p2     = $this->makeProgramme($year, 'ASIR');
        $p3     = $this->makeProgramme($year, 'DAM');
        $this->persist($centre, $year, $p1, $p2, $p3);

        $results = $this->repo->findByAcademicYearOrdered($year);

        self::assertCount(3, $results);
        self::assertSame('ASIR', $results[0]->getName());
        self::assertSame('DAM',  $results[1]->getName());
        self::assertSame('SMR',  $results[2]->getName());
    }

    public function testFindByAcademicYearOrderedExcludesOtherYears(): void
    {
        $centre = $this->makeCentre('41000011');
        $yearA  = $this->makeYear($centre, '2024-2025');
        $yearB  = $this->makeYear($centre, '2023-2024');
        $pA     = $this->makeProgramme($yearA, 'DAM');
        $pB     = $this->makeProgramme($yearB, 'DAW');
        $this->persist($centre, $yearA, $yearB, $pA, $pB);

        $results = $this->repo->findByAcademicYearOrdered($yearA);

        self::assertCount(1, $results);
        self::assertSame('DAM', $results[0]->getName());
    }

    // ── findByAcademicYearAndId ───────────────────────────────────────────────

    public function testFindByAcademicYearAndIdReturnsProgramme(): void
    {
        $centre    = $this->makeCentre('41000005');
        $year      = $this->makeYear($centre);
        $programme = $this->makeProgramme($year, 'DAM');
        $this->persist($centre, $year, $programme);

        $result = $this->repo->findByAcademicYearAndId($year, $programme->getId()->toRfc4122());

        self::assertNotNull($result);
        self::assertSame($programme->getId()->toRfc4122(), $result->getId()->toRfc4122());
    }

    public function testFindByAcademicYearAndIdReturnsNullForDifferentYear(): void
    {
        $centre    = $this->makeCentre('41000006');
        $yearA     = $this->makeYear($centre, '2024-2025');
        $yearB     = $this->makeYear($centre, '2023-2024');
        $programme = $this->makeProgramme($yearA, 'DAM');
        $this->persist($centre, $yearA, $yearB, $programme);

        self::assertNull($this->repo->findByAcademicYearAndId($yearB, $programme->getId()->toRfc4122()));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeCentre(string $code): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('IES ' . $code)->setCity('Sevilla');
    }

    private function makeYear(EducationalCentre $centre, string $name = '2024-2025'): AcademicYear
    {
        return (new AcademicYear())->setName($name)->setEducationalCentre($centre);
    }

    private function makeProgramme(AcademicYear $year, string $name): Programme
    {
        return (new Programme())->setName($name)->setAcademicYear($year);
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }
}
