<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Student;
use App\Entity\Teacher;
use Symfony\Component\Uid\Uuid;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sanction>
 */
class SanctionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sanction::class);
    }

    public function findById(string $id): ?Sanction
    {
        $result = $this->createQueryBuilder('s')
            ->where('s.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Sanction ? $result : null;
    }

    /**
     * Builds the sanction listing query for the given centre, restricted to the viewer's
     * visibility and ordered by creation date DESC.
     *
     * Supported filters:
     *   search         string — student name or group name
     *   studentId      string — UUID of a student
     *   effectiveToday bool   — only sanctions in effect today (notified, within date range)
     *   pendingOnly    bool   — only sanctions without a successful communication
     *
     * @param array<string, mixed> $filters
     * @return Query<null, Sanction>
     */
    public function createFilteredQuery(
        EducationalCentre $centre,
        Teacher $viewer,
        array $filters = [],
    ): Query {
        $qb = $this->createQueryBuilder('s')
            ->addSelect('st', 'g')
            ->join('s.student', 'st')
            ->join('s.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('s.createdAt', 'DESC');

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->distinct()
               ->join('s.reports', 'r')
               ->andWhere(
                   $qb->expr()->orX(
                       'r.registeredBy = :viewer',
                       ':viewer MEMBER OF g.tutors',
                   )
               )
               ->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        $search = $filters['search'] ?? '';
        if (is_string($search) && $search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(st.name.firstName) LIKE LOWER(:search)',
                    'LOWER(st.name.lastName) LIKE LOWER(:search)',
                    'LOWER(g.name) LIKE LOWER(:search)',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        $studentId = $filters['studentId'] ?? '';
        if (is_string($studentId) && $studentId !== '') {
            $qb->andWhere('s.student = :studentId')
               ->setParameter('studentId', $studentId, 'uuid');
        }

        if (($filters['effectiveToday'] ?? false) === true) {
            $qb->andWhere('s.notifiedCommunication IS NOT NULL')
               ->andWhere('s.effectiveFrom <= :today')
               ->andWhere('s.effectiveTo IS NULL OR s.effectiveTo >= :today')
               ->setParameter('today', new \DateTimeImmutable('today'));
        }

        if (($filters['pendingOnly'] ?? false) === true) {
            $qb->andWhere('s.notifiedCommunication IS NULL');
        }

        return $qb->getQuery();
    }

    /**
     * Counts sanctions currently in effect today (notified and within their effective date range)
     * that are visible to the viewer.
     */
    public function countActiveByCentre(EducationalCentre $centre, Teacher $viewer, \DateTimeImmutable $today): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT s.id)')
            ->join('s.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('s.notifiedCommunication IS NOT NULL')
            ->andWhere('s.effectiveFrom <= :today')
            ->andWhere('s.effectiveTo IS NULL OR s.effectiveTo >= :today')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('today', $today);

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->join('s.reports', 'r')
               ->andWhere(
                   $qb->expr()->orX(
                       'r.registeredBy = :viewer',
                       ':viewer MEMBER OF g.tutors',
                   )
               )
               ->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns (student, group) pairs for the centre's active academic year with their report counts:
     * sanctionable (not prescribed, not yet sanctioned), serious (sanctionable with serious behavior),
     * and prescribed. Results are sorted by sanctionable count DESC, then by student name.
     *
     * @return array{
     *     rows: list<array{studentId: string, firstName: string, lastName: string, groupId: string, groupName: string, sanctionableCount: int, seriousCount: int, prescribedCount: int}>,
     *     total: int
     * }
     */
    public function findStudentStatsForCentre(
        EducationalCentre $centre,
        string $search = '',
        int $page = 1,
        int $perPage = 20,
    ): array {
        $activeYear = $centre->getActiveAcademicYear();
        if (!$activeYear instanceof AcademicYear) {
            return ['rows' => [], 'total' => 0];
        }

        $dql = '
            SELECT
                s.id AS studentId,
                s.name.firstName AS firstName,
                s.name.lastName AS lastName,
                g.id AS groupId,
                g.name AS groupName,
                (SELECT COUNT(r1.id) FROM App\Entity\IncidentReport r1
                 WHERE r1.student = s AND r1.group = g
                 AND r1.prescribedAt IS NULL AND r1.sanction IS NULL
                 AND r1.notifiedCommunication IS NOT NULL) AS sanctionableCount,
                (SELECT COUNT(DISTINCT r2.id)
                 FROM App\Entity\IncidentReport r2
                 JOIN r2.behaviors b2
                 JOIN b2.category bc2
                 WHERE r2.student = s AND r2.group = g
                 AND r2.prescribedAt IS NULL AND r2.sanction IS NULL
                 AND r2.notifiedCommunication IS NOT NULL
                 AND bc2.serious = :serious) AS seriousCount,
                (SELECT COUNT(r3.id) FROM App\Entity\IncidentReport r3
                 WHERE r3.student = s AND r3.group = g
                 AND r3.prescribedAt IS NOT NULL) AS prescribedCount
            FROM App\Entity\Student s
            JOIN s.groups g
            JOIN g.programmeYear py
            JOIN py.programme prog
            JOIN prog.academicYear ay
            WHERE ay = :activeYear
            AND EXISTS (
                SELECT r0.id FROM App\Entity\IncidentReport r0
                WHERE r0.student = s AND r0.group = g
                AND r0.prescribedAt IS NULL AND r0.sanction IS NULL
            )
        ';

        if ($search !== '') {
            $dql .= ' AND (LOWER(s.name.firstName) LIKE LOWER(:search)
                           OR LOWER(s.name.lastName) LIKE LOWER(:search)
                           OR LOWER(g.name) LIKE LOWER(:search))';
        }

        $query = $this->getEntityManager()
            ->createQuery($dql)
            ->setParameter('activeYear', $activeYear->getId(), 'uuid')
            ->setParameter('serious', true);

        if ($search !== '') {
            $query->setParameter('search', '%' . $search . '%');
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = $query->getArrayResult();

        usort($raw, static function (array $a, array $b): int {
            $aSanc = $a['sanctionableCount'];
            $bSanc = $b['sanctionableCount'];
            $diff  = (is_scalar($bSanc) ? intval($bSanc) : 0) - (is_scalar($aSanc) ? intval($aSanc) : 0);
            if ($diff !== 0) {
                return $diff;
            }
            $aLast = $a['lastName'];
            $bLast = $b['lastName'];
            $lc    = strcmp(is_string($aLast) ? $aLast : '', is_string($bLast) ? $bLast : '');
            if ($lc !== 0) {
                return $lc;
            }
            $aFirst = $a['firstName'];
            $bFirst = $b['firstName'];
            return strcmp(is_string($aFirst) ? $aFirst : '', is_string($bFirst) ? $bFirst : '');
        });

        $total  = count($raw);
        $offset = max(0, ($page - 1) * $perPage);

        /** @var list<array{studentId: string, firstName: string, lastName: string, groupId: string, groupName: string, sanctionableCount: int, seriousCount: int, prescribedCount: int}> $rows */
        $rows = array_map(
            function (array $row): array {
                $studentId = $row['studentId'];
                $groupId   = $row['groupId'];
                $firstName = $row['firstName'];
                $lastName  = $row['lastName'];
                $groupName = $row['groupName'];
                $sanc      = $row['sanctionableCount'];
                $serious   = $row['seriousCount'];
                $prescribed = $row['prescribedCount'];

                return [
                    'studentId'         => $studentId instanceof Uuid ? $studentId->toRfc4122() : '',
                    'firstName'         => is_string($firstName) ? $firstName : '',
                    'lastName'          => is_string($lastName) ? $lastName : '',
                    'groupId'           => $groupId instanceof Uuid ? $groupId->toRfc4122() : '',
                    'groupName'         => is_string($groupName) ? $groupName : '',
                    'sanctionableCount' => is_scalar($sanc) ? intval($sanc) : 0,
                    'seriousCount'      => is_scalar($serious) ? intval($serious) : 0,
                    'prescribedCount'   => is_scalar($prescribed) ? intval($prescribed) : 0,
                ];
            },
            array_slice($raw, $offset, $perPage),
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Returns sanctions pending notification (no successful communication yet) visible to the viewer,
     * ordered by creation date ascending (oldest first).
     *
     * @return list<Sanction>
     */
    public function findPendingNotification(EducationalCentre $centre, Teacher $viewer): array
    {
        $qb = $this->createQueryBuilder('s')
            ->join('s.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('s.notifiedCommunication IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('s.createdAt', 'ASC');

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->distinct()
               ->join('s.reports', 'r')
               ->andWhere(
                   $qb->expr()->orX(
                       'r.registeredBy = :viewer',
                       ':viewer MEMBER OF g.tutors',
                   )
               )
               ->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        /** @var list<Sanction> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Returns all sanctions with a start date set for the given academic year, ordered by start date.
     * Not filtered by viewer: the calendar shows every dated sanction of the year to any teacher.
     *
     * @return list<Sanction>
     */
    public function findWithDatesForAcademicYear(AcademicYear $year): array
    {
        /** @var list<Sanction> $result */
        $result = $this->createQueryBuilder('s')
            ->addSelect('st', 'g')
            ->join('s.student', 'st')
            ->join('s.group', 'g')
            ->where('s.academicYear = :year')
            ->andWhere('s.effectiveFrom IS NOT NULL')
            ->andWhere('s.notifiedCommunication IS NOT NULL')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('s.effectiveFrom', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Returns reports for a student/group that are not prescribed and not yet in a sanction.
     *
     * @return list<IncidentReport>
     */
    public function findEligibleReports(Student $student, Group $group): array
    {
        /** @var list<IncidentReport> $result */
        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('r')
            ->from(IncidentReport::class, 'r')
            ->where('r.student = :student')
            ->andWhere('r.group = :group')
            ->andWhere('r.prescribedAt IS NULL')
            ->andWhere('r.sanction IS NULL')
            ->andWhere('r.notifiedCommunication IS NOT NULL')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('group', $group->getId(), 'uuid')
            ->orderBy('r.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
