<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

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
use App\Entity\Course;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use App\Repository\StudentRepository;
use App\Tests\Integration\RepositoryTestCase;

class StudentRepositoryTest extends RepositoryTestCase
{
    private StudentRepository $repo;

    private int $nextReportNumber = 0;

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

    // ── findTutoredSummary ───────────────────────────────────────────────────

    public function testFindTutoredSummaryReturnsOnlyGroupsTutoredByViewer(): void
    {
        $world  = $this->makeTutorshipWorld('41002001');
        $tutor  = $this->makeTeacher('tutored.tutor.1');
        $this->persist($tutor);
        $world['group']->addTutor($tutor);
        $world['group']->addStudent($world['student']);
        $this->flush();

        $rows = $this->repo->findTutoredSummary($tutor, $world['year']);

        self::assertCount(1, $rows);
        self::assertSame($world['student']->getId()->toRfc4122(), $rows[0]['studentId']);
        self::assertSame($world['group']->getId()->toRfc4122(), $rows[0]['groupId']);
    }

    public function testFindTutoredSummaryExcludesGroupsWherViewerIsOnlyTeacher(): void
    {
        $world   = $this->makeTutorshipWorld('41002002');
        $teacher = $this->makeTeacher('tutored.plain.2');
        $this->persist($teacher);
        $world['group']->addTeacher($teacher, 'Matemáticas');
        $world['group']->addStudent($world['student']);
        $this->flush();

        $rows = $this->repo->findTutoredSummary($teacher, $world['year']);

        self::assertCount(0, $rows);
    }

    public function testFindTutoredSummaryCountsReportsScopedToStudentAndGroup(): void
    {
        $world = $this->makeTutorshipWorld('41002003');
        $tutor = $this->makeTeacher('tutored.counts.3');
        $this->persist($tutor);
        $world['group']->addTutor($tutor);
        $world['group']->addStudent($world['student']);
        $this->flush();

        $this->makeTutoredReport($world);
        $this->makeTutoredReport($world);

        $rows = $this->repo->findTutoredSummary($tutor, $world['year']);

        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]['reportsTotal']);
    }

    public function testFindTutoredSummaryCountsSeriousReportsSeparately(): void
    {
        $world = $this->makeTutorshipWorld('41002004');
        $tutor = $this->makeTeacher('tutored.serious.4');
        $this->persist($tutor);
        $world['group']->addTutor($tutor);
        $world['group']->addStudent($world['student']);
        $this->flush();

        $this->makeTutoredReport($world);
        $this->makeTutoredReport($world, serious: true);

        $rows = $this->repo->findTutoredSummary($tutor, $world['year']);

        self::assertSame(2, $rows[0]['reportsTotal']);
        self::assertSame(1, $rows[0]['reportsSerious']);
    }

    public function testFindTutoredSummaryCountsUnnotifiedReports(): void
    {
        $world = $this->makeTutorshipWorld('41002005');
        $tutor = $this->makeTeacher('tutored.unnotified.5');
        $this->persist($tutor);
        $world['group']->addTutor($tutor);
        $world['group']->addStudent($world['student']);
        $this->flush();

        $this->makeTutoredReport($world, notified: true);
        $this->makeTutoredReport($world, notified: false);

        $rows = $this->repo->findTutoredSummary($tutor, $world['year']);

        self::assertSame(2, $rows[0]['reportsTotal']);
        self::assertSame(1, $rows[0]['reportsUnnotified']);
    }

    public function testFindTutoredSummaryCountsPrescribedReports(): void
    {
        $world = $this->makeTutorshipWorld('41002006');
        $tutor = $this->makeTeacher('tutored.prescribed.6');
        $this->persist($tutor);
        $world['group']->addTutor($tutor);
        $world['group']->addStudent($world['student']);
        $this->flush();

        $this->makeTutoredReport($world);
        $prescribed = $this->makeTutoredReport($world);
        $prescribed->setPrescribedAt(new \DateTimeImmutable());
        $this->flush();

        $rows = $this->repo->findTutoredSummary($tutor, $world['year']);

        self::assertSame(1, $rows[0]['reportsPrescribed']);
    }

    public function testFindTutoredSummaryCountsSanctionsAndUnnotifiedSanctions(): void
    {
        $world = $this->makeTutorshipWorld('41002007');
        $tutor = $this->makeTeacher('tutored.sanctions.7');
        $this->persist($tutor);
        $world['group']->addTutor($tutor);
        $world['group']->addStudent($world['student']);
        $this->flush();

        $this->makeTutoredSanction($world, notified: true);
        $this->makeTutoredSanction($world, notified: false);

        $rows = $this->repo->findTutoredSummary($tutor, $world['year']);

        self::assertSame(2, $rows[0]['sanctionsTotal']);
        self::assertSame(1, $rows[0]['sanctionsUnnotified']);
    }

    public function testFindTutoredSummaryStudentInTwoTutoredGroupsAppearsTwice(): void
    {
        $world  = $this->makeTutorshipWorld('41002008');
        $tutor  = $this->makeTeacher('tutored.twogroups.8');
        $this->persist($tutor);
        $groupB = (new Group())->setName('1ºB')->setCourse($world['group']->getCourse());
        $this->persist($groupB);

        $world['group']->addTutor($tutor);
        $groupB->addTutor($tutor);
        $world['group']->addStudent($world['student']);
        $groupB->addStudent($world['student']);
        $this->flush();

        $rows = $this->repo->findTutoredSummary($tutor, $world['year']);

        self::assertCount(2, $rows);
    }

    public function testFindTutoredSummaryFiltersBySearch(): void
    {
        $world  = $this->makeTutorshipWorld('41002009');
        $tutor  = $this->makeTeacher('tutored.search.9');
        $this->persist($tutor);
        $studentB = (new Student(new PersonName('Pedro', 'Martínez')))->setStudentId('NIE-TUT-' . uniqid('', false));
        $this->persist($studentB);

        $world['group']->addTutor($tutor);
        $world['group']->addStudent($world['student']);
        $world['group']->addStudent($studentB);
        $this->flush();

        $rows = $this->repo->findTutoredSummary($tutor, $world['year'], ['search' => 'García']);

        self::assertCount(1, $rows);
        self::assertSame('García', $rows[0]['lastName']);
    }

    public function testFindTutoredSummaryFiltersByGroupId(): void
    {
        $world  = $this->makeTutorshipWorld('41002010');
        $tutor  = $this->makeTeacher('tutored.groupfilter.10');
        $this->persist($tutor);
        $groupB   = (new Group())->setName('1ºB')->setCourse($world['group']->getCourse());
        $studentB = (new Student(new PersonName('Pedro', 'Martínez')))->setStudentId('NIE-TUT-' . uniqid('', false));
        $this->persist($groupB, $studentB);

        $world['group']->addTutor($tutor);
        $groupB->addTutor($tutor);
        $world['group']->addStudent($world['student']);
        $groupB->addStudent($studentB);
        $this->flush();

        $rows = $this->repo->findTutoredSummary($tutor, $world['year'], ['groupId' => $world['group']->getId()->toRfc4122()]);

        self::assertCount(1, $rows);
        self::assertSame($world['student']->getId()->toRfc4122(), $rows[0]['studentId']);
    }

    public function testFindTutoredSummarySortsByReportsTotalDescending(): void
    {
        $world    = $this->makeTutorshipWorld('41002011');
        $tutor    = $this->makeTeacher('tutored.sort.11');
        $this->persist($tutor);
        $studentB = (new Student(new PersonName('Bea', 'López')))->setStudentId('NIE-TUT-' . uniqid('', false));
        $this->persist($studentB);

        $world['group']->addTutor($tutor);
        $world['group']->addStudent($world['student']);
        $world['group']->addStudent($studentB);
        $this->flush();

        $this->makeTutoredReport($world);
        $worldB = $world;
        $worldB['student'] = $studentB;
        $this->makeTutoredReport($worldB);
        $this->makeTutoredReport($worldB);

        $rows = $this->repo->findTutoredSummary($tutor, $world['year'], ['sort' => 'reportsTotal', 'sortDir' => 'desc']);

        self::assertSame('López', $rows[0]['lastName']);
        self::assertSame(2, $rows[0]['reportsTotal']);
        self::assertSame('García', $rows[1]['lastName']);
        self::assertSame(1, $rows[1]['reportsTotal']);
    }

    public function testFindTutoredSummaryDefaultSortIsByLastNameThenFirstName(): void
    {
        $world    = $this->makeTutorshipWorld('41002012');
        $tutor    = $this->makeTeacher('tutored.defaultsort.12');
        $this->persist($tutor);
        $studentB = (new Student(new PersonName('Bea', 'Alonso')))->setStudentId('NIE-TUT-' . uniqid('', false));
        $this->persist($studentB);

        $world['group']->addTutor($tutor);
        $world['group']->addStudent($world['student']); // García
        $world['group']->addStudent($studentB);          // Alonso
        $this->flush();

        $rows = $this->repo->findTutoredSummary($tutor, $world['year']);

        self::assertSame('Alonso', $rows[0]['lastName']);
        self::assertSame('García', $rows[1]['lastName']);
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

    /**
     * Builds and persists Centre → Year → Course → Group → Student, plus a
     * non-serious behavior category/behavior, for the findTutoredSummary() tests.
     *
     * @return array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, seriousBehavior: IncidentBehavior, method: CommunicationMethod}
     */
    private function makeTutorshipWorld(string $centreCode): array
    {
        $centre   = (new EducationalCentre())->setCode($centreCode)->setName('IES ' . $centreCode)->setCity('Sevilla');
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $course   = (new Course())->setName('DAW')->setAcademicYear($year);
        $group    = (new Group())->setName('1ºA')->setCourse($course);
        $student  = (new Student(new PersonName('Ana', 'García')))->setStudentId('NIE-TUT-' . uniqid('', false));
        $category = (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName('Contrarias')
            ->setSerious(false)
            ->setPosition(0);
        $behavior = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($category)
            ->setName('Perturbación')
            ->setPosition(0)
            ->setActive(true);
        $seriousCategory = (new IncidentBehaviorCategory())
            ->setEducationalCentre($centre)
            ->setName('Graves')
            ->setSerious(true)
            ->setPosition(1);
        $seriousBehavior = (new IncidentBehavior())
            ->setEducationalCentre($centre)
            ->setCategory($seriousCategory)
            ->setName('Agresión')
            ->setPosition(0)
            ->setActive(true);
        $method = (new CommunicationMethod())
            ->setEducationalCentre($centre)
            ->setName('Llamada telefónica')
            ->setPosition(0)
            ->setActive(true);

        $centre->setActiveAcademicYear($year);
        $this->persist($centre, $year, $course, $group, $student, $category, $behavior, $seriousCategory, $seriousBehavior, $method);

        return compact('centre', 'year', 'group', 'student', 'behavior', 'seriousBehavior', 'method');
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, seriousBehavior: IncidentBehavior, method: CommunicationMethod} $world
     */
    private function makeTutoredReport(array $world, bool $serious = false, bool $notified = true): IncidentReport
    {
        $creator = $this->makeTeacher('tutored.report.creator.' . uniqid('', false));
        $this->persist($creator);

        $report = (new IncidentReport())
            ->setAcademicYear($world['year'])
            ->setNumber(++$this->nextReportNumber)
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setDescription('<p>Test.</p>')
            ->setExpelledFromClass(false);
        $report->addBehavior($serious ? $world['seriousBehavior'] : $world['behavior']);
        $this->persist($report);

        if ($notified) {
            $communication = Communication::forIncidentReport($report, $world['method'], $creator, new \DateTimeImmutable(), CommunicationResult::Notified);
            $this->persist($communication);
            $report->setNotifiedCommunication($communication);
            $this->flush();
        }

        return $report;
    }

    /**
     * @param array{centre: EducationalCentre, year: AcademicYear, group: Group, student: Student, behavior: IncidentBehavior, seriousBehavior: IncidentBehavior, method: CommunicationMethod} $world
     */
    private function makeTutoredSanction(array $world, bool $notified = true): Sanction
    {
        $creator = $this->makeTeacher('tutored.sanction.creator.' . uniqid('', false));
        $this->persist($creator);

        $sanction = (new Sanction())
            ->setAcademicYear($world['year'])
            ->setStudent($world['student'])
            ->setGroup($world['group'])
            ->setRegisteredBy($creator)
            ->setDetails('Detalles de prueba.')
            ->setNoMeasureApplied(false);
        $this->persist($sanction);

        if ($notified) {
            $communication = Communication::forSanction($sanction, $world['method'], $creator, new \DateTimeImmutable(), CommunicationResult::Notified);
            $this->persist($communication);
            $sanction->setNotifiedCommunication($communication);
            $this->flush();
        }

        return $sanction;
    }
}
