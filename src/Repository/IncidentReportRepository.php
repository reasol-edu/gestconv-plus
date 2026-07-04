<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\Student;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IncidentReport>
 */
class IncidentReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentReport::class);
    }

    /**
     * Builds a pageable/filterable query for incident reports visible to the viewer.
     *
     * Visibility rules:
     *  - Global admin              → all reports of the centre
     *  - Centre admin              → all reports of the centre
     *  - Committee member / counselor → all reports of the centre
     *  - Other teacher             → only reports they registered OR reports for groups they tutor
     *
     * Accepted filter keys (all optional):
     *   search   string   — fulltext search
     *   ownOnly  bool     — only viewer's own reports
     *   groupId  string   — UUID of a group
     *   studentId string  — UUID of a student
     *   dateFrom string   — Y-m-d lower bound
     *   dateTo   string   — Y-m-d upper bound
     *   serious  bool     — filter by behavior severity
     *   expelled bool     — filter by expelled flag
     *   sort     string   — column key
     *   sortDir  string   — 'asc'|'desc'
     *
     * @param array<string, mixed> $filters
     * @return Query<null, IncidentReport>
     */
    public function createFilteredQuery(
        EducationalCentre $centre,
        Teacher $viewer,
        array $filters = [],
    ): Query {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('g', 's', 't', 'beh', 'bc')
            ->join('r.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->join('r.student', 's')
            ->join('r.registeredBy', 't')
            ->leftJoin('r.behaviors', 'beh')
            ->leftJoin('beh.category', 'bc')
            ->where('ay.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid');

        // Visibility restriction for non-admins
        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'r.registeredBy = :viewer',
                    ':viewer MEMBER OF g.tutors',
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        // ownOnly
        $ownOnly = $filters['ownOnly'] ?? false;
        if ($ownOnly === true) {
            $qb->andWhere('r.registeredBy = :viewerOwn')
               ->setParameter('viewerOwn', $viewer->getId(), 'uuid');
        }

        // groupId
        $groupId = $filters['groupId'] ?? '';
        if (is_string($groupId) && $groupId !== '') {
            $qb->andWhere('g.id = :groupId')
               ->setParameter('groupId', $groupId, 'uuid');
        }

        // studentId
        $studentId = $filters['studentId'] ?? '';
        if (is_string($studentId) && $studentId !== '') {
            $qb->andWhere('r.student = :studentId')
               ->setParameter('studentId', $studentId, 'uuid');
        }

        // dateFrom
        $dateFrom = $filters['dateFrom'] ?? '';
        if (is_string($dateFrom) && $dateFrom !== '') {
            try {
                $from = new \DateTimeImmutable($dateFrom);
                $qb->andWhere('r.occurredAt >= :dateFrom')
                   ->setParameter('dateFrom', $from->setTime(0, 0, 0));
            } catch (\Exception) {
                // ignore invalid date
            }
        }

        // dateTo
        $dateTo = $filters['dateTo'] ?? '';
        if (is_string($dateTo) && $dateTo !== '') {
            try {
                $to = new \DateTimeImmutable($dateTo);
                $qb->andWhere('r.occurredAt <= :dateTo')
                   ->setParameter('dateTo', $to->setTime(23, 59, 59));
            } catch (\Exception) {
                // ignore invalid date
            }
        }

        // serious
        $serious = $filters['serious'] ?? null;
        if (is_bool($serious)) {
            $qb->andWhere('bc.serious = :serious')
               ->setParameter('serious', $serious);
        }

        // expelled
        $expelled = $filters['expelled'] ?? null;
        if (is_bool($expelled)) {
            $qb->andWhere('r.expelledFromClass = :expelled')
               ->setParameter('expelled', $expelled);
        }

        // search
        $search = $filters['search'] ?? '';
        if (is_string($search) && $search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(s.name.firstName) LIKE LOWER(:search)',
                    'LOWER(s.name.lastName) LIKE LOWER(:search)',
                    'LOWER(t.name.firstName) LIKE LOWER(:search)',
                    'LOWER(t.name.lastName) LIKE LOWER(:search)',
                    'LOWER(g.name) LIKE LOWER(:search)',
                    'LOWER(beh.name) LIKE LOWER(:search)',
                    'LOWER(r.description) LIKE LOWER(:search)',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        // sorting
        $sortDirRaw = $filters['sortDir'] ?? 'desc';
        $sortDir    = is_string($sortDirRaw) && strtolower($sortDirRaw) === 'asc' ? 'ASC' : 'DESC';

        $sortRaw = $filters['sort'] ?? '';
        $sort    = is_string($sortRaw) ? $sortRaw : '';

        match ($sort) {
            'student' => $qb->orderBy('s.name.lastName', $sortDir)->addOrderBy('s.name.firstName', $sortDir),
            'teacher' => $qb->orderBy('t.name.lastName', $sortDir)->addOrderBy('t.name.firstName', $sortDir),
            'group'   => $qb->orderBy('g.name', $sortDir),
            default   => $qb->orderBy('r.occurredAt', 'DESC'),
        };

        return $qb->distinct()->getQuery();
    }

    /**
     * Count reports in the last $days days that the viewer can see.
     */
    public function countRecentByCentre(EducationalCentre $centre, Teacher $viewer, int $days = 30): int
    {
        $since = new \DateTimeImmutable('-' . $days . ' days');

        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.id)')
            ->join('r.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('r.occurredAt >= :since')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('since', $since);

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'r.registeredBy = :viewer',
                    ':viewer MEMBER OF g.tutors',
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns the distinct groups that have at least one incident report visible to the viewer,
     * ordered by group name. Used to populate the group filter dropdown.
     *
     * @return Group[]
     */
    public function findGroupsWithReports(EducationalCentre $centre, Teacher $viewer): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('g')
            ->distinct()
            ->from(Group::class, 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->join(IncidentReport::class, 'r', 'WITH', 'r.group = g')
            ->where('ay.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('g.name', 'ASC');

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'r.registeredBy = :viewer',
                    ':viewer MEMBER OF g.tutors',
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        /** @var Group[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Searches student+group pairs for the incident report form autocomplete.
     *
     * @return list<array{student: Student, group: Group}>
     */
    public function searchStudentGroupPairs(
        EducationalCentre $centre,
        string $q,
        int $limit = 20,
    ): array {
        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        /** @var list<Student> $students */
        $students = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('s', 'g')
            ->from(Student::class, 's')
            ->join('s.groups', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay = :year')
            ->andWhere(
                'LOWER(s.name.firstName) LIKE LOWER(:search)
                 OR LOWER(s.name.lastName) LIKE LOWER(:search)
                 OR LOWER(s.studentId) LIKE LOWER(:search)
                 OR LOWER(g.name) LIKE LOWER(:search)'
            )
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('search', '%' . $q . '%')
            ->orderBy('s.name.lastName', 'ASC')
            ->addOrderBy('s.name.firstName', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var list<array{student: Student, group: Group}> $pairs */
        $pairs = [];

        foreach ($students as $student) {
            foreach ($student->getGroups() as $group) {
                // Only include groups from the active year
                if ($group->getProgrammeYear()->getProgramme()->getAcademicYear() === $year) {
                    $pairs[] = ['student' => $student, 'group' => $group];
                }
            }
        }

        return array_slice($pairs, 0, $limit);
    }

    public function nextNumberForYear(AcademicYear $year): int
    {
        $max = $this->createQueryBuilder('r')
            ->select('MAX(r.number)')
            ->where('r.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($max ?? 0) + 1;
    }

    /**
     * Returns reports pending notification (no successful communication yet) visible to the viewer,
     * ordered by occurrence date ascending (oldest first).
     *
     * @return list<IncidentReport>
     */
    public function findPendingNotification(EducationalCentre $centre, Teacher $viewer): array
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('r.notifiedCommunication IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('r.occurredAt', 'ASC');

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'r.registeredBy = :viewer',
                    ':viewer MEMBER OF g.tutors',
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        /** @var list<IncidentReport> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function findById(string $id): ?IncidentReport
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof IncidentReport ? $result : null;
    }
}
