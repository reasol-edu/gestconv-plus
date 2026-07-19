<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;

use App\Entity\Student;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Student>
 */
class StudentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Student::class);
    }

    /** Columnas ordenables: clave pública => campo DQL (allowlist contra inyección). */
    private const SORTABLE = [
        'nie' => 's.studentId',
        'name' => 's.name.lastName',
    ];

    /**
     * Paginated query: students in groups belonging to the centre's active academic year.
     * Supports text search (NIE, first name, last name) and optional group filter.
     *
     * @return Query<null, Student>
     */
    public function createByCentreFilteredQuery(
        EducationalCentre $centre,
        string $search = '',
        string $groupId = '',
        string $sort = '',
        string $sortDir = 'asc',
        ?AcademicYear $year = null,
    ): Query {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return $this->findNoneQuery();
        }
        $qb = $this->createQueryBuilder('s')
            ->distinct()
            ->join('s.groups', 'g')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->where('ay = :activeYear')
            ->setParameter('activeYear', $year->getId(), 'uuid');

        $dir = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
        if (isset(self::SORTABLE[$sort])) {
            $qb->orderBy(self::SORTABLE[$sort], $dir);
            if ($sort === 'name') {
                $qb->addOrderBy('s.name.firstName', $dir);
            }
        } else {
            $qb->orderBy('s.name.lastName', 'ASC')
               ->addOrderBy('s.name.firstName', 'ASC');
        }

        if ($groupId !== '') {
            $qb->andWhere('g.id = :groupId')
               ->setParameter('groupId', $groupId, 'uuid');
        }

        if ($search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(s.studentId) LIKE LOWER(:search)',
                    'LOWER(s.name.firstName) LIKE LOWER(:search)',
                    'LOWER(s.name.lastName) LIKE LOWER(:search)',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery();
    }

    /**
     * Same filters as createByCentreFilteredQuery, but returns the full list with
     * the groups collection fetch-joined (separate alias so the group filter does
     * not truncate the hydrated collection).
     *
     * @return list<Student>
     */
    public function findByCentreFilteredWithGroups(
        EducationalCentre $centre,
        string $search = '',
        string $groupId = '',
        ?AcademicYear $year = null,
    ): array {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }
        $qb = $this->createQueryBuilder('s')
            ->distinct()
            ->join('s.groups', 'g')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->leftJoin('s.groups', 'sg')->addSelect('sg')
            ->where('ay = :activeYear')
            ->setParameter('activeYear', $year->getId(), 'uuid')
            ->orderBy('s.name.lastName', 'ASC')
            ->addOrderBy('s.name.firstName', 'ASC');

        if ($groupId !== '') {
            $qb->andWhere('g.id = :groupId')
               ->setParameter('groupId', $groupId, 'uuid');
        }

        if ($search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(s.studentId) LIKE LOWER(:search)',
                    'LOWER(s.name.firstName) LIKE LOWER(:search)',
                    'LOWER(s.name.lastName) LIKE LOWER(:search)',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return Query<null, Student> */
    public function findNoneQuery(): Query
    {
        return $this->createQueryBuilder('s')
            ->where('1 = 0')
            ->getQuery();
    }

    public function findById(string $id): ?Student
    {
        $result = $this->createQueryBuilder('s')
            ->where('s.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Student ? $result : null;
    }

    public function findByStudentId(string $studentId): ?Student
    {
        return $this->findOneBy(['studentId' => $studentId]);
    }

    /**
     * A student with no groups yet has no centre affiliation to check against
     * (e.g. just imported, not yet assigned) and is treated as belonging to
     * whichever centre is asking. Otherwise, at least one of its groups must
     * resolve to the given centre.
     */
    public function belongsToCentre(Student $student, EducationalCentre $centre): bool
    {
        if ($student->getGroups()->isEmpty()) {
            return true;
        }

        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.groups', 'g')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->where('s.id = :id')
            ->andWhere('ay.educationalCentre = :centre')
            ->setParameter('id', $student->getId(), 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countByActiveYear(EducationalCentre $centre, ?Teacher $viewer = null, ?AcademicYear $year = null): int
    {
        $year ??= $centre->getActiveAcademicYear();
        if ($year === null) {
            return 0;
        }

        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT s.id)')
            ->join('s.groups', 'g')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->where('ay = :year')
            ->setParameter('year', $year->getId(), 'uuid');

        if ($viewer !== null && !$viewer->isAdmin()) {
            $qb->leftJoin('g.groupTeachers', 'gt')
                ->andWhere($qb->expr()->orX(
                    'EXISTS(SELECT 1 FROM ' . AcademicYear::class . ' vay JOIN vay.educationalCentre vvec JOIN vvec.admins vadm WHERE vay.id = :year AND vadm.id = :viewer)',
                    ':viewer MEMBER OF g.tutors',
                    'gt.teacher = :viewer',
                ))->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Quick search by name / NIE for the global search palette.
     * Admins see all students; non-admin teachers see only students from their groups.
     *
     * @return list<Student>
     */
    public function searchByCentre(EducationalCentre $centre, string $q, int $limit = 5, ?Teacher $viewer = null): array
    {
        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        $qb = $this->createQueryBuilder('s')
            ->distinct()
            ->join('s.groups', 'g')
            ->join('g.course', 'c')
            ->join('c.academicYear', 'ay')
            ->where('ay = :activeYear')
            ->setParameter('activeYear', $year->getId(), 'uuid')
            ->orderBy('s.name.lastName', 'ASC')
            ->addOrderBy('s.name.firstName', 'ASC');

        $qb->andWhere(
            $qb->expr()->orX(
                'LOWER(s.studentId) LIKE LOWER(:search)',
                'LOWER(s.name.firstName) LIKE LOWER(:search)',
                'LOWER(s.name.lastName) LIKE LOWER(:search)',
            )
        )->setParameter('search', '%' . $q . '%');

        if ($viewer !== null && !$viewer->isAdmin() && !$centre->getAdmins()->contains($viewer)) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'EXISTS (SELECT 1 FROM ' . Group::class . ' vg JOIN vg.groupTeachers vgt WHERE vg.id = g.id AND vgt.teacher = :viewerId)',
                    'EXISTS (SELECT 1 FROM ' . Group::class . ' vg2 JOIN vg2.tutors vgtu WHERE vg2.id = g.id AND vgtu.id = :viewerId)',
                )
            )->setParameter('viewerId', $viewer->getId(), 'uuid');
        }

        /** @var list<Student> $result */
        $result = $qb->setMaxResults($limit)->getQuery()->getResult();

        return $result;
    }

    /** Columnas ordenables para {@see findTutoredSummary()} (allowlist contra inyección). */
    private const TUTORED_SORTABLE = ['name', 'group', 'reportsTotal', 'reportsUnnotified', 'reportsPrescribed', 'sanctionsTotal', 'sanctionsUnnotified'];

    /**
     * One row per (student, group) pair for every group the viewer tutors in the given
     * academic year, with report/sanction counts scoped to that student+group. A student
     * tutored in two different groups by the same viewer appears once per group.
     *
     * Supported filters: search (name/group text), groupId.
     *
     * @param array<string, mixed> $filters
     * @return list<array{
     *     studentId: string, firstName: string, lastName: string, groupId: string, groupName: string,
     *     reportsTotal: int, reportsSerious: int, reportsUnnotified: int, reportsPrescribed: int,
     *     sanctionsTotal: int, sanctionsUnnotified: int
     * }>
     */
    public function findTutoredSummary(Teacher $viewer, AcademicYear $year, array $filters = []): array
    {
        $dql = '
            SELECT
                s.id AS studentId,
                s.name.firstName AS firstName,
                s.name.lastName AS lastName,
                g.id AS groupId,
                g.name AS groupName,
                (SELECT COUNT(r1.id) FROM App\Entity\IncidentReport r1
                 WHERE r1.student = s AND r1.group = g) AS reportsTotal,
                (SELECT COUNT(DISTINCT r2.id) FROM App\Entity\IncidentReport r2
                 JOIN r2.behaviors b2 JOIN b2.category bc2
                 WHERE r2.student = s AND r2.group = g AND bc2.serious = :serious) AS reportsSerious,
                (SELECT COUNT(r3.id) FROM App\Entity\IncidentReport r3
                 WHERE r3.student = s AND r3.group = g AND r3.notifiedCommunication IS NULL) AS reportsUnnotified,
                (SELECT COUNT(r4.id) FROM App\Entity\IncidentReport r4
                 WHERE r4.student = s AND r4.group = g AND r4.prescribedAt IS NOT NULL) AS reportsPrescribed,
                (SELECT COUNT(sa1.id) FROM App\Entity\Sanction sa1
                 WHERE sa1.student = s AND sa1.group = g) AS sanctionsTotal,
                (SELECT COUNT(sa2.id) FROM App\Entity\Sanction sa2
                 WHERE sa2.student = s AND sa2.group = g AND sa2.notifiedCommunication IS NULL) AS sanctionsUnnotified
            FROM App\Entity\Student s
            JOIN s.groups g
            JOIN g.course c
            JOIN c.academicYear ay
            WHERE ay = :year
            AND :viewer MEMBER OF g.tutors
        ';

        $groupId = $filters['groupId'] ?? '';
        if (is_string($groupId) && $groupId !== '') {
            $dql .= ' AND g.id = :groupId';
        }

        $search = $filters['search'] ?? '';
        if (is_string($search) && $search !== '') {
            $dql .= ' AND (LOWER(s.name.firstName) LIKE LOWER(:search)
                           OR LOWER(s.name.lastName) LIKE LOWER(:search)
                           OR LOWER(g.name) LIKE LOWER(:search))';
        }

        $query = $this->getEntityManager()
            ->createQuery($dql)
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('viewer', $viewer->getId(), 'uuid')
            ->setParameter('serious', true);

        if (is_string($groupId) && $groupId !== '') {
            $query->setParameter('groupId', $groupId, 'uuid');
        }
        if (is_string($search) && $search !== '') {
            $query->setParameter('search', '%' . $search . '%');
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = $query->getArrayResult();

        /** @var list<array{studentId: string, firstName: string, lastName: string, groupId: string, groupName: string, reportsTotal: int, reportsSerious: int, reportsUnnotified: int, reportsPrescribed: int, sanctionsTotal: int, sanctionsUnnotified: int}> $rows */
        $rows = array_map(
            static function (array $row): array {
                $studentId = $row['studentId'];
                $groupId   = $row['groupId'];

                return [
                    'studentId'           => $studentId instanceof Uuid ? $studentId->toRfc4122() : '',
                    'firstName'           => is_string($row['firstName']) ? $row['firstName'] : '',
                    'lastName'            => is_string($row['lastName']) ? $row['lastName'] : '',
                    'groupId'             => $groupId instanceof Uuid ? $groupId->toRfc4122() : '',
                    'groupName'           => is_string($row['groupName']) ? $row['groupName'] : '',
                    'reportsTotal'        => is_scalar($row['reportsTotal']) ? intval($row['reportsTotal']) : 0,
                    'reportsSerious'      => is_scalar($row['reportsSerious']) ? intval($row['reportsSerious']) : 0,
                    'reportsUnnotified'   => is_scalar($row['reportsUnnotified']) ? intval($row['reportsUnnotified']) : 0,
                    'reportsPrescribed'   => is_scalar($row['reportsPrescribed']) ? intval($row['reportsPrescribed']) : 0,
                    'sanctionsTotal'      => is_scalar($row['sanctionsTotal']) ? intval($row['sanctionsTotal']) : 0,
                    'sanctionsUnnotified' => is_scalar($row['sanctionsUnnotified']) ? intval($row['sanctionsUnnotified']) : 0,
                ];
            },
            $raw,
        );

        $sortRaw = $filters['sort'] ?? '';
        $sort    = is_string($sortRaw) && in_array($sortRaw, self::TUTORED_SORTABLE, true) ? $sortRaw : '';
        $sortDirRaw = $filters['sortDir'] ?? 'asc';
        $desc       = is_string($sortDirRaw) && strtolower($sortDirRaw) === 'desc';

        usort($rows, static function (array $a, array $b) use ($sort, $desc): int {
            $cmp = match ($sort) {
                'group'                => strcmp($a['groupName'], $b['groupName']) ?: strcmp($a['lastName'], $b['lastName']) ?: strcmp($a['firstName'], $b['firstName']),
                'reportsTotal'         => $a['reportsTotal'] <=> $b['reportsTotal'],
                'reportsUnnotified'    => $a['reportsUnnotified'] <=> $b['reportsUnnotified'],
                'reportsPrescribed'    => $a['reportsPrescribed'] <=> $b['reportsPrescribed'],
                'sanctionsTotal'       => $a['sanctionsTotal'] <=> $b['sanctionsTotal'],
                'sanctionsUnnotified'  => $a['sanctionsUnnotified'] <=> $b['sanctionsUnnotified'],
                default                => strcmp($a['lastName'], $b['lastName']) ?: strcmp($a['firstName'], $b['firstName']) ?: strcmp($a['groupName'], $b['groupName']),
            };

            return $desc ? -$cmp : $cmp;
        });

        return $rows;
    }
}
