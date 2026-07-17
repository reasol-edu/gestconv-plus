<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\AbsenceRepository;
use App\Tests\Integration\RepositoryTestCase;

class AbsenceRepositoryTest extends RepositoryTestCase
{
    private AbsenceRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var AbsenceRepository $repo */
        $repo       = self::getContainer()->get(AbsenceRepository::class);
        $this->repo = $repo;
    }

    public function testCreateFilteredQueryIsScopedToTheGivenYear(): void
    {
        $yearA = $this->makeYear();
        $yearB = $this->makeYear();
        $this->makeAbsence($yearA, $this->makeTeacher('a'));
        $this->makeAbsence($yearB, $this->makeTeacher('b'));

        $results = $this->repo->createFilteredQuery($yearA)->getResult();

        self::assertCount(1, $results);
    }

    public function testCreateFilteredQueryFiltersByTeacher(): void
    {
        $year     = $this->makeYear();
        $teacherA = $this->makeTeacher('teacher.a');
        $teacherB = $this->makeTeacher('teacher.b');
        $this->makeAbsence($year, $teacherA);
        $this->makeAbsence($year, $teacherB);

        $results = $this->repo->createFilteredQuery($year, ['teacherId' => $teacherA->getId()->toRfc4122()])->getResult();

        self::assertCount(1, $results);
        self::assertSame($teacherA->getId()->toRfc4122(), $results[0]->getTeacher()->getId()->toRfc4122());
    }

    public function testCreateFilteredQueryFiltersByDateRangeUsingOverlapSemantics(): void
    {
        $year    = $this->makeYear();
        $teacher = $this->makeTeacher('overlap');
        $before  = $this->makeAbsence($year, $teacher, '2026-01-01', '2026-01-05');
        $inside  = $this->makeAbsence($year, $teacher, '2026-01-10', '2026-01-12');
        $after   = $this->makeAbsence($year, $teacher, '2026-01-20', '2026-01-25');

        $results = $this->repo->createFilteredQuery($year, [
            'dateFrom' => '2026-01-08',
            'dateTo'   => '2026-01-15',
        ])->getResult();

        self::assertCount(1, $results);
        self::assertSame($inside->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testCreateFilteredQueryIgnoresInvalidDates(): void
    {
        $year = $this->makeYear();
        $this->makeAbsence($year, $this->makeTeacher('invalid.date'));

        $results = $this->repo->createFilteredQuery($year, ['dateFrom' => 'no es una fecha'])->getResult();

        self::assertCount(1, $results);
    }

    public function testCreateFilteredQueryOrdersByEndDateDescending(): void
    {
        $year    = $this->makeYear();
        $teacher = $this->makeTeacher('ordering');
        $earlier = $this->makeAbsence($year, $teacher, '2026-01-01', '2026-01-05');
        $later   = $this->makeAbsence($year, $teacher, '2026-02-01', '2026-02-05');

        $results = $this->repo->createFilteredQuery($year)->getResult();

        self::assertCount(2, $results);
        self::assertSame($later->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
        self::assertSame($earlier->getId()->toRfc4122(), $results[1]->getId()->toRfc4122());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeYear(): AcademicYear
    {
        $centre = (new EducationalCentre())->setCode('41000' . substr(md5(uniqid('', true)), 0, 3))->setName('IES')->setCity('Sevilla');
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $year);

        return $year;
    }

    private function makeTeacher(string $username): Teacher
    {
        $teacher = (new Teacher(new PersonName('Test', 'Teacher')))->setUsername($username . '.' . uniqid('', true));
        $this->persist($teacher);

        return $teacher;
    }

    private function makeAbsence(AcademicYear $year, Teacher $teacher, string $start = '2026-01-01', string $end = '2026-01-05'): Absence
    {
        $absence = (new Absence())
            ->setTeacher($teacher)
            ->setAcademicYear($year)
            ->setStartDate(new \DateTimeImmutable($start))
            ->setEndDate(new \DateTimeImmutable($end));
        $this->persist($absence);

        return $absence;
    }
}
