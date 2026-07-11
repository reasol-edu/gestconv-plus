<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\Programme;
use App\Entity\ProgrammeYear;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Group>
 */
class GroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    public function isTeacherInProgramme(Teacher $teacher, Programme $programme): bool
    {
        return $this->createQueryBuilder('g')
            ->select('1')
            ->join('g.programmeYear', 'py')
            ->leftJoin('g.teachers', 't')
            ->where('py.programme = :programme')
            ->andWhere(':teacher MEMBER OF g.tutors OR t.id = :teacher')
            ->setParameter('programme', $programme->getId(), 'uuid')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
    }

    /**
     * Number of groups per level, keyed by level UUID (RFC4122). Single grouped query.
     *
     * @param  ProgrammeYear[] $levels
     * @return array<string, int>
     */
    public function countByLevel(array $levels): array
    {
        if ($levels === []) {
            return [];
        }

        // Cada UUID se vincula individualmente con el tipo 'uuid' explícito: pasar el
        // array completo como un único parámetro IN (:levels) hace que Doctrine infiera
        // un ArrayParameterType genérico (string) que ignora la conversión de UuidType
        // (binaria en MySQL/SQLite, nativa en PostgreSQL), y la consulta no encuentra
        // ninguna fila aunque los datos existan.
        $qb           = $this->createQueryBuilder('g')
            ->select('IDENTITY(g.programmeYear) AS lid', 'COUNT(g.id) AS cnt');
        $placeholders = [];
        foreach ($levels as $i => $level) {
            $placeholders[] = ":level{$i}";
            $qb->setParameter("level{$i}", $level->getId(), 'uuid');
        }

        /** @var list<array<string, int|string>> $rows */
        $rows = $qb
            ->where('g.programmeYear IN (' . implode(', ', $placeholders) . ')')
            ->groupBy('g.programmeYear')
            ->getQuery()
            ->getScalarResult();

        $uuidNorm = [];
        foreach ($levels as $level) {
            $rfc = $level->getId()->toRfc4122();
            $uuidNorm[$rfc]                      = $rfc;
            $uuidNorm[$level->getId()->toBinary()] = $rfc;
        }

        $map = [];
        foreach ($rows as $row) {
            $key = $uuidNorm[(string) $row['lid']] ?? (string) $row['lid'];
            $map[$key] = (int) $row['cnt'];
        }

        return $map;
    }

    /** @return Group[] */
    public function findByLevelOrderedByName(ProgrammeYear $level): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.programmeYear = :level')
            ->setParameter('level', $level->getId(), 'uuid')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByLevelAndId(ProgrammeYear $level, string $id): ?Group
    {
        $result = $this->createQueryBuilder('g')
            ->where('g.programmeYear = :level')
            ->andWhere('g.id = :id')
            ->setParameter('level', $level->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Group ? $result : null;
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?Group
    {
        $result = $this->createQueryBuilder('g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('g.id = :id')
            ->andWhere('ay.educationalCentre = :centre')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Group ? $result : null;
    }

    /**
     * Returns all groups (with students eagerly loaded) that belong to ProgrammeYears
     * of the given programme. Ordered by level name → group name → student surname.
     *
     * @return Group[]
     */
    public function findByProgrammeWithStudents(Programme $programme): array
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.students', 's')->addSelect('s')
            ->join('g.programmeYear', 'py')
            ->where('py.programme = :programme')
            ->setParameter('programme', $programme->getId(), 'uuid')
            ->orderBy('py.name', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->addOrderBy('s.name.lastName', 'ASC')
            ->addOrderBy('s.name.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByActiveYearOfCentre(EducationalCentre $centre, ?AcademicYear $year = null): int
    {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return 0;
        }

        return (int) $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->where('prog.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Group[] */
    public function findByActiveYearOfCentreOrderedByName(EducationalCentre $centre, ?AcademicYear $year = null): array
    {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        return $this->createQueryBuilder('g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->where('prog.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the groups of the centre's active year that the viewer tutors, ordered by name.
     *
     * @return Group[]
     */
    public function findTutoredByActiveYear(EducationalCentre $centre, Teacher $viewer, ?AcademicYear $year = null): array
    {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        return $this->createQueryBuilder('g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->where('prog.academicYear = :year')
            ->andWhere(':viewer MEMBER OF g.tutors')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('viewer', $viewer->getId(), 'uuid')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns student and teacher counts for every group in the given academic year,
     * keyed by group UUID (RFC4122). Single query; avoids N+1 per group.
     *
     * @param  Group[] $groups  All groups in the year (used to normalise binary UUIDs from getScalarResult)
     * @return array<string, array{students: int, teachers: int}>
     */
    public function findCountsByAcademicYear(\App\Entity\AcademicYear $year, array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        /** @var list<array<string, int|string>> $rows */
        $rows = $this->getEntityManager()
            ->createQuery('
                SELECT g.id AS gid,
                       COUNT(DISTINCT s.id) AS students,
                       COUNT(DISTINCT t.id) AS teachers
                FROM App\Entity\Group g
                JOIN g.programmeYear py
                JOIN py.programme prog
                LEFT JOIN g.students s
                LEFT JOIN g.teachers t
                WHERE prog.academicYear = :year
                GROUP BY g.id
            ')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getScalarResult();

        // getScalarResult() returns UUIDs in binary form on MySQL.
        // Build a lookup map so either representation normalises to RFC4122.
        $uuidNorm = [];
        foreach ($groups as $group) {
            $rfc = $group->getId()->toRfc4122();
            $uuidNorm[$rfc]                        = $rfc;
            $uuidNorm[$group->getId()->toBinary()]  = $rfc;
        }
        $normalize = static fn (int|string $raw): string =>
            $uuidNorm[(string) $raw] ?? (string) $raw;

        $map = [];
        foreach ($rows as $row) {
            $map[$normalize($row['gid'])] = [
                'students' => (int) $row['students'],
                'teachers' => (int) $row['teachers'],
            ];
        }

        return $map;
    }

    /**
     * Returns groups for the centre's active year with programme and level data eagerly loaded,
     * sorted by programme → level → group name.
     *
     * @return Group[]
     */
    public function findByActiveYearOfCentreWithProgramme(EducationalCentre $centre, ?AcademicYear $year = null): array
    {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        return $this->createQueryBuilder('g')
            ->join('g.programmeYear', 'py')->addSelect('py')
            ->join('py.programme', 'prog')->addSelect('prog')
            ->where('prog.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('prog.name', 'ASC')
            ->addOrderBy('py.name', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
