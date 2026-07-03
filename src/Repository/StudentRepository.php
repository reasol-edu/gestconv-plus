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
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
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
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
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
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
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
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->where('prog.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid');

        if ($viewer !== null && !$viewer->isAdmin()) {
            $qb->andWhere($qb->expr()->orX(
                'EXISTS(SELECT 1 FROM ' . AcademicYear::class . ' vay JOIN vay.educationalCentre vvec JOIN vvec.admins vadm WHERE vay.id = :year AND vadm.id = :viewer)',
                'EXISTS(SELECT 1 FROM ' . Group::class . ' vg JOIN vg.programmeYear vgpy WHERE vgpy.programme = prog AND (:viewer MEMBER OF vg.tutors OR :viewer MEMBER OF vg.teachers))',
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
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
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
                    'EXISTS (SELECT 1 FROM ' . Group::class . ' vg JOIN vg.teachers vgt WHERE vg.id = g.id AND vgt.id = :viewerId)',
                    'EXISTS (SELECT 1 FROM ' . Group::class . ' vg2 JOIN vg2.tutors vgtu WHERE vg2.id = g.id AND vgtu.id = :viewerId)',
                )
            )->setParameter('viewerId', $viewer->getId(), 'uuid');
        }

        /** @var list<Student> $result */
        $result = $qb->setMaxResults($limit)->getQuery()->getResult();

        return $result;
    }
}
