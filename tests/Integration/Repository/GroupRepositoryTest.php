<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\Course;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\GroupRepository;
use App\Tests\Integration\RepositoryTestCase;

class GroupRepositoryTest extends RepositoryTestCase
{
    private GroupRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var GroupRepository $repo */
        $repo       = self::getContainer()->get(GroupRepository::class);
        $this->repo = $repo;
    }

    // ── findByCourseOrderedByName ─────────────────────────────────────────────

    public function testFindByCourseOrderedByNameReturnsSortedGroups(): void
    {
        [, , $course] = $this->makeChain('41000001');
        $g1 = $this->makeGroup($course, 'DAM2C');
        $g2 = $this->makeGroup($course, 'DAM2A');
        $g3 = $this->makeGroup($course, 'DAM2B');
        $this->persist($g1, $g2, $g3);

        $results = $this->repo->findByCourseOrderedByName($course);

        self::assertCount(3, $results);
        self::assertSame('DAM2A', $results[0]->getName());
        self::assertSame('DAM2B', $results[1]->getName());
        self::assertSame('DAM2C', $results[2]->getName());
    }

    public function testFindByCourseOrderedByNameExcludesOtherCourses(): void
    {
        [$centre, $year, $courseA] = $this->makeChain('41000002');
        $courseB = (new Course())->setName('DAW')->setAcademicYear($year);
        $this->persist($courseB);

        $gA = $this->makeGroup($courseA, 'Grupo A');
        $gB = $this->makeGroup($courseB, 'Grupo B');
        $this->persist($gA, $gB);

        $results = $this->repo->findByCourseOrderedByName($courseA);

        self::assertCount(1, $results);
        self::assertSame('Grupo A', $results[0]->getName());
    }

    // ── findByCourseAndId ─────────────────────────────────────────────────────

    public function testFindByCourseAndIdReturnsGroup(): void
    {
        [, , $course] = $this->makeChain('41000003');
        $group = $this->makeGroup($course, 'DAM2A');
        $this->persist($group);

        $result = $this->repo->findByCourseAndId($course, $group->getId()->toRfc4122());

        self::assertNotNull($result);
        self::assertSame($group->getId()->toRfc4122(), $result->getId()->toRfc4122());
    }

    public function testFindByCourseAndIdReturnsNullForDifferentCourse(): void
    {
        [$centre, $year, $courseA] = $this->makeChain('41000004');
        $courseB = (new Course())->setName('DAW')->setAcademicYear($year);
        $this->persist($courseB);

        $group = $this->makeGroup($courseA, 'DAM1A');
        $this->persist($group);

        self::assertNull($this->repo->findByCourseAndId($courseB, $group->getId()->toRfc4122()));
    }

    // ── findByIdAndCentre ─────────────────────────────────────────────────────

    public function testFindByIdAndCentreReturnsGroup(): void
    {
        [$centre, , $course] = $this->makeChain('41000018');
        $group = $this->makeGroup($course, 'DAM2A');
        $this->persist($group);

        $result = $this->repo->findByIdAndCentre($group->getId()->toRfc4122(), $centre);

        self::assertNotNull($result);
        self::assertSame($group->getId()->toRfc4122(), $result->getId()->toRfc4122());
    }

    public function testFindByIdAndCentreReturnsNullForDifferentCentre(): void
    {
        [, , $courseA] = $this->makeChain('41000019');
        [$centreB]     = $this->makeChain('41000020');

        $group = $this->makeGroup($courseA, 'DAM1A');
        $this->persist($group);

        self::assertNull($this->repo->findByIdAndCentre($group->getId()->toRfc4122(), $centreB));
    }

    // ── findByYearWithStudents ────────────────────────────────────────────────

    public function testFindByYearWithStudentsReturnsGroupsWithStudentsEagerLoaded(): void
    {
        [, $year, $course] = $this->makeChain('41000005');
        $group   = $this->makeGroup($course, 'DAM2A');
        $student = new Student(new PersonName('Ana', 'Garcia'));
        $student->setStudentId('ST001');
        $this->persist($group, $student);

        $student->addGroup($group);
        $this->flush();

        $results = $this->repo->findByYearWithStudents($year);

        self::assertCount(1, $results);
        self::assertCount(1, $results[0]->getStudents());
    }

    public function testFindByYearWithStudentsExcludesOtherYears(): void
    {
        [$centre, $year, $courseA] = $this->makeChain('41000006');

        // Build second course in same year
        $courseB = (new Course())->setName('DAW')->setAcademicYear($year);
        $this->persist($courseB);

        $gA = $this->makeGroup($courseA, 'DAM1A');
        $gB = $this->makeGroup($courseB, 'DAW1A');
        $this->persist($gA, $gB);

        $results = $this->repo->findByYearWithStudents($year);

        self::assertCount(2, $results);
    }

    // ── findByActiveYearOfCentreOrderedByName ─────────────────────────────────

    public function testFindByActiveYearOfCentreOrderedByNameReturnsGroups(): void
    {
        [$centre, $year, $course] = $this->makeChain('41000007');
        $centre->setActiveAcademicYear($year);
        $this->flush();

        $g1 = $this->makeGroup($course, 'Grupo B');
        $g2 = $this->makeGroup($course, 'Grupo A');
        $this->persist($g1, $g2);

        $results = $this->repo->findByActiveYearOfCentreOrderedByName($centre);

        self::assertCount(2, $results);
        self::assertSame('Grupo A', $results[0]->getName());
        self::assertSame('Grupo B', $results[1]->getName());
    }

    public function testFindByActiveYearOfCentreOrderedByNameReturnsEmptyWhenNoActiveYear(): void
    {
        [, , $course] = $this->makeChain('41000008');
        // No activeAcademicYear set
        $group = $this->makeGroup($course, 'Grupo A');
        $this->persist($group);

        // Re-fetch centre without active year
        [$centre] = $this->makeChain('41000008b');
        self::assertCount(0, $this->repo->findByActiveYearOfCentreOrderedByName($centre));
    }

    // ── isTeacherInCourse ─────────────────────────────────────────────────────

    public function testIsTeacherInCourseReturnsTrueWhenTeacherIsTutor(): void
    {
        [, , $course] = $this->makeChain('41000009');
        $teacher = $this->makeTeacher('tutor.one');
        $group   = $this->makeGroup($course, 'DAM1A');
        $this->persist($teacher, $group);
        $group->addTutor($teacher);
        $this->flush();

        self::assertTrue($this->repo->isTeacherInCourse($teacher, $course));
    }

    public function testIsTeacherInCourseReturnsTrueWhenTeacherIsGroupTeacher(): void
    {
        [, , $course] = $this->makeChain('41000010');
        $teacher = $this->makeTeacher('teacher.one');
        $group   = $this->makeGroup($course, 'DAM1A');
        $this->persist($teacher, $group);
        $group->addTeacher($teacher);
        $this->flush();

        self::assertTrue($this->repo->isTeacherInCourse($teacher, $course));
    }

    public function testIsTeacherInCourseReturnsFalseWhenTeacherHasNoRole(): void
    {
        [, , $course] = $this->makeChain('41000011');
        $teacher = $this->makeTeacher('no.role');
        $this->persist($teacher);

        self::assertFalse($this->repo->isTeacherInCourse($teacher, $course));
    }

    public function testIsTeacherInCourseReturnsFalseForDifferentCourse(): void
    {
        [$centre, $year, $courseA] = $this->makeChain('41000012');
        $courseB = (new Course())->setName('DAW')->setAcademicYear($year);
        $teacher = $this->makeTeacher('tutor.other');
        $groupB  = $this->makeGroup($courseB, 'DAW1A');
        $this->persist($courseB, $teacher, $groupB);
        $groupB->addTutor($teacher);
        $this->flush();

        // Teacher is tutor in courseB, not in courseA
        self::assertFalse($this->repo->isTeacherInCourse($teacher, $courseA));
    }

    // ── findCountsByAcademicYear ──────────────────────────────────────────────

    public function testFindCountsByAcademicYearReturnsStudentAndTeacherCounts(): void
    {
        [$centre, $year, $course] = $this->makeChain('41000013');
        $group   = $this->makeGroup($course, 'DAM1A');
        $teacher = $this->makeTeacher('teacher.counts');
        $student = new Student(new PersonName('Ana', 'Garcia'));
        $student->setStudentId('ST001');
        $this->persist($group, $teacher, $student);
        $group->addTeacher($teacher);
        $student->addGroup($group);
        $this->flush();

        $counts = $this->repo->findCountsByAcademicYear($year, [$group]);

        $id = $group->getId()->toRfc4122();
        self::assertArrayHasKey($id, $counts);
        self::assertSame(1, $counts[$id]['students']);
        self::assertSame(1, $counts[$id]['teachers']);
    }

    public function testFindCountsByAcademicYearReturnsZerosForEmptyGroup(): void
    {
        [$centre, $year, $course] = $this->makeChain('41000014');
        $group = $this->makeGroup($course, 'DAM1A');
        $this->persist($group);

        $counts = $this->repo->findCountsByAcademicYear($year, [$group]);

        $id = $group->getId()->toRfc4122();
        self::assertSame(0, $counts[$id]['students']);
        self::assertSame(0, $counts[$id]['teachers']);
    }

    public function testFindCountsByAcademicYearReturnsEmptyForNoGroups(): void
    {
        [$centre, $year] = $this->makeChain('41000015');

        self::assertSame([], $this->repo->findCountsByAcademicYear($year, []));
    }

    // ── findByActiveYearOfCentreWithCourse ────────────────────────────────────

    public function testFindByActiveYearOfCentreWithCourseReturnsOrderedGroups(): void
    {
        [$centre, $year, $course] = $this->makeChain('41000016');
        $centre->setActiveAcademicYear($year);
        $this->flush();

        $g1 = $this->makeGroup($course, 'Grupo B');
        $g2 = $this->makeGroup($course, 'Grupo A');
        $this->persist($g1, $g2);

        $results = $this->repo->findByActiveYearOfCentreWithCourse($centre);

        self::assertCount(2, $results);
        self::assertSame('Grupo A', $results[0]->getName());
        self::assertSame('Grupo B', $results[1]->getName());
    }

    public function testFindByActiveYearOfCentreWithCourseReturnsEmptyWhenNoActiveYear(): void
    {
        [, , $course] = $this->makeChain('41000017');
        $this->persist($this->makeGroup($course, 'Grupo A'));

        [$centre] = $this->makeChain('41000017b');
        self::assertCount(0, $this->repo->findByActiveYearOfCentreWithCourse($centre));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Creates and persists Centre → Year → Course.
     *
     * @return array{EducationalCentre, AcademicYear, Course}
     */
    private function makeChain(string $centreCode): array
    {
        $centre = (new EducationalCentre())->setCode($centreCode)->setName('IES ' . $centreCode)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $course = (new Course())->setName('DAM')->setAcademicYear($year);
        $this->persist($centre, $year, $course);
        return [$centre, $year, $course];
    }

    private function makeGroup(Course $course, string $name): Group
    {
        return (new Group())->setName($name)->setCourse($course);
    }

    private function makeTeacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username);
    }
}
