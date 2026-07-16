<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\PersonName;
use App\Entity\Course;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\StudentRepository;
use App\Tests\Integration\RepositoryTestCase;

class StudentRepositoryTest extends RepositoryTestCase
{
    private StudentRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var StudentRepository $repo */
        $repo       = self::getContainer()->get(StudentRepository::class);
        $this->repo = $repo;
    }

    // ── findById ─────────────────────────────────────────────────────────────

    public function testFindByIdReturnsStudent(): void
    {
        $student = $this->makeStudent('ST001', 'Ana', 'Garcia');
        $this->persist($student);

        $result = $this->repo->findById($student->getId()->toRfc4122());

        self::assertNotNull($result);
        self::assertSame($student->getId()->toRfc4122(), $result->getId()->toRfc4122());
    }

    public function testFindByIdReturnsNullForNonExistentId(): void
    {
        self::assertNull($this->repo->findById('00000000-0000-0000-0000-000000000000'));
    }

    // ── findByStudentId ───────────────────────────────────────────────────────

    public function testFindByStudentIdReturnsStudent(): void
    {
        $student = $this->makeStudent('ST001', 'Ana', 'Garcia');
        $this->persist($student);

        $result = $this->repo->findByStudentId('ST001');

        self::assertNotNull($result);
        self::assertSame('ST001', $result->getStudentId());
    }

    public function testFindByStudentIdReturnsNullForUnknownId(): void
    {
        $student = $this->makeStudent('ST001');
        $this->persist($student);

        self::assertNull($this->repo->findByStudentId('UNKNOWN'));
    }

    // ── createByCentreFilteredQuery ───────────────────────────────────────────

    public function testCreateByCentreFilteredQueryReturnsStudentsInActiveYear(): void
    {
        [$centre, $year, $group] = $this->makeGroupChain('41000001');
        $centre->setActiveAcademicYear($year);
        $this->flush();

        $s1 = $this->makeStudent('ST001', 'Carlos', 'Ruiz');
        $s2 = $this->makeStudent('ST002', 'Ana',    'Garcia');
        $this->persist($s1, $s2);

        $s1->addGroup($group);
        $s2->addGroup($group);
        $this->flush();

        $results = $this->repo->createByCentreFilteredQuery($centre)->getResult();

        self::assertCount(2, $results);
        // Ordered by lastName ASC, firstName ASC
        self::assertSame('ST002', $results[0]->getStudentId()); // Garcia, Ana
        self::assertSame('ST001', $results[1]->getStudentId()); // Ruiz, Carlos
    }

    public function testCreateByCentreFilteredQueryFiltersBySearch(): void
    {
        [$centre, $year, $group] = $this->makeGroupChain('41000002');
        $centre->setActiveAcademicYear($year);
        $this->flush();

        $s1 = $this->makeStudent('ST001', 'Ana',   'Garcia');
        $s2 = $this->makeStudent('ST002', 'Pedro', 'Lopez');
        $this->persist($s1, $s2);

        $s1->addGroup($group);
        $s2->addGroup($group);
        $this->flush();

        $results = $this->repo->createByCentreFilteredQuery($centre, 'Garcia')->getResult();

        self::assertCount(1, $results);
        self::assertSame('ST001', $results[0]->getStudentId());
    }

    public function testCreateByCentreFilteredQueryFiltersByFirstNameCaseInsensitive(): void
    {
        [$centre, $year, $group] = $this->makeGroupChain('41000009');
        $centre->setActiveAcademicYear($year);
        $this->flush();

        $s1 = $this->makeStudent('ST001', 'ANA',   'GARCIA');
        $s2 = $this->makeStudent('ST002', 'PEDRO', 'LOPEZ');
        $this->persist($s1, $s2);

        $s1->addGroup($group);
        $s2->addGroup($group);
        $this->flush();

        $results = $this->repo->createByCentreFilteredQuery($centre, 'ana')->getResult();

        self::assertCount(1, $results);
        self::assertSame('ST001', $results[0]->getStudentId());
    }

    public function testSearchByCentreByFirstNameCaseInsensitive(): void
    {
        [$centre, $year, $group] = $this->makeGroupChain('41000010');
        $centre->setActiveAcademicYear($year);
        $this->flush();

        $s1 = $this->makeStudent('ST001', 'ANA',   'GARCIA');
        $s2 = $this->makeStudent('ST002', 'PEDRO', 'LOPEZ');
        $this->persist($s1, $s2);

        $s1->addGroup($group);
        $s2->addGroup($group);
        $this->flush();

        $results = $this->repo->searchByCentre($centre, 'ana');

        self::assertCount(1, $results);
        self::assertSame('ST001', $results[0]->getStudentId());
    }

    public function testCreateByCentreFilteredQueryFiltersByGroupId(): void
    {
        [$centre, $year, $groupA] = $this->makeGroupChain('41000003');
        // Create a second group in the same course
        $course  = $groupA->getCourse();
        $groupB  = (new Group())->setName('B')->setCourse($course);
        $this->persist($groupB);

        $centre->setActiveAcademicYear($year);
        $this->flush();

        $s1 = $this->makeStudent('ST001', 'Ana',   'Garcia');
        $s2 = $this->makeStudent('ST002', 'Pedro', 'Lopez');
        $this->persist($s1, $s2);

        $s1->addGroup($groupA);
        $s2->addGroup($groupB);
        $this->flush();

        $results = $this->repo->createByCentreFilteredQuery(
            $centre,
            '',
            $groupA->getId()->toRfc4122()
        )->getResult();

        self::assertCount(1, $results);
        self::assertSame('ST001', $results[0]->getStudentId());
    }

    // ── countByActiveYear ─────────────────────────────────────────────────────

    public function testCountByActiveYearWithoutViewer(): void
    {
        [$centre, , $groupA, $groupB] = $this->makeTwoCourseChain('41001001');

        $s1 = $this->makeStudent('ST001'); $s2 = $this->makeStudent('ST002');
        $s3 = $this->makeStudent('ST003'); $s4 = $this->makeStudent('ST004');
        $this->persist($s1, $s2, $s3, $s4);
        $s1->addGroup($groupA); $s2->addGroup($groupA);
        $s3->addGroup($groupB); $s4->addGroup($groupB);
        $this->flush();

        self::assertSame(4, $this->repo->countByActiveYear($centre));
    }

    public function testCountByActiveYearGlobalAdminSeesAll(): void
    {
        [$centre, , $groupA, $groupB] = $this->makeTwoCourseChain('41001002');

        $s1 = $this->makeStudent('ST001'); $s2 = $this->makeStudent('ST002');
        $s3 = $this->makeStudent('ST003');
        $this->persist($s1, $s2, $s3);
        $s1->addGroup($groupA); $s2->addGroup($groupA); $s3->addGroup($groupB);
        $this->flush();

        $admin = $this->makeTeacher('admin');
        $admin->setAdmin(true);
        $this->persist($admin);

        self::assertSame(3, $this->repo->countByActiveYear($centre, $admin));
    }

    public function testCountByActiveYearCentreAdminSeesAll(): void
    {
        [$centre, $year, $groupA, $groupB] = $this->makeTwoCourseChain('41001003');

        $s1 = $this->makeStudent('ST001'); $s2 = $this->makeStudent('ST002');
        $s3 = $this->makeStudent('ST003');
        $this->persist($s1, $s2, $s3);
        $s1->addGroup($groupA); $s2->addGroup($groupA); $s3->addGroup($groupB);
        $this->flush();

        $centreAdmin = $this->makeTeacher('centreadmin');
        $year->addTeacher($centreAdmin);
        $centre->addAdmin($centreAdmin);
        $this->persist($centreAdmin);
        $this->flush();

        self::assertSame(3, $this->repo->countByActiveYear($centre, $centreAdmin));
    }

    public function testCountByActiveYearGroupTeacherSeesOwnGroupStudents(): void
    {
        // Teacher in groupA2 sees only students of their own group(s).
        [$centre, $year, $groupA, , $courseA] = $this->makeTwoCourseChain('41001006');

        $groupA2 = (new Group())->setName('A2')->setCourse($courseA);
        $this->persist($groupA2);

        $s1 = $this->makeStudent('ST001'); $s2 = $this->makeStudent('ST002');
        $s3 = $this->makeStudent('ST003');
        $this->persist($s1, $s2, $s3);
        $s1->addGroup($groupA);  // groupA — not teacher's group
        $s2->addGroup($groupA);  // groupA — not teacher's group
        $s3->addGroup($groupA2); // groupA2 — teacher's group
        $this->flush();

        $teacher = $this->makeTeacher('groupteacher');
        $year->addTeacher($teacher);
        $groupA2->addTeacher($teacher, 'Matemáticas');
        $this->persist($teacher);
        $this->flush();

        // Teacher is in groupA2 only → sees only groupA2's 1 student
        self::assertSame(1, $this->repo->countByActiveYear($centre, $teacher));
    }

    public function testCountByActiveYearUnrelatedTeacherSeesZero(): void
    {
        [$centre, $year, $groupA, $groupB] = $this->makeTwoCourseChain('41001007');

        $s1 = $this->makeStudent('ST001'); $s2 = $this->makeStudent('ST002');
        $this->persist($s1, $s2);
        $s1->addGroup($groupA); $s2->addGroup($groupB);
        $this->flush();

        $unrelated = $this->makeTeacher('unrelated');
        $year->addTeacher($unrelated);
        $this->persist($unrelated);
        $this->flush();

        self::assertSame(0, $this->repo->countByActiveYear($centre, $unrelated));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Builds and persists Centre → Year → Course → Group.
     *
     * @return array{EducationalCentre, AcademicYear, Group}
     */
    private function makeGroupChain(string $centreCode): array
    {
        $centre = (new EducationalCentre())->setCode($centreCode)->setName('IES ' . $centreCode)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $course = (new Course())->setName('DAM')->setAcademicYear($year);
        $group  = (new Group())->setName('DAM1A')->setCourse($course);
        $this->persist($centre, $year, $course, $group);
        return [$centre, $year, $group];
    }

    private function makeStudent(string $studentId, string $firstName = 'Test', string $lastName = 'Student'): Student
    {
        return (new Student(new PersonName($firstName, $lastName)))->setStudentId($studentId);
    }

    private function makeTeacher(string $username): Teacher
    {
        $t = new Teacher(new PersonName($username, 'Test'));
        $t->setUsername($username);
        $t->setPassword('x');
        return $t;
    }

    /**
     * Builds and persists:
     *   Centre → Year
     *     CourseA → GroupA
     *     CourseB → GroupB
     *
     * @return array{EducationalCentre, AcademicYear, Group, Group, Course}
     */
    private function makeTwoCourseChain(string $centreCode): array
    {
        $centre = (new EducationalCentre())->setCode($centreCode)->setName('IES ' . $centreCode)->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);

        $courseA = (new Course())->setName('CourseA')->setAcademicYear($year);
        $groupA  = (new Group())->setName('GA')->setCourse($courseA);

        $courseB = (new Course())->setName('CourseB')->setAcademicYear($year);
        $groupB  = (new Group())->setName('GB')->setCourse($courseB);

        $this->persist($centre, $year, $courseA, $groupA, $courseB, $groupB);
        return [$centre, $year, $groupA, $groupB, $courseA];
    }
}
