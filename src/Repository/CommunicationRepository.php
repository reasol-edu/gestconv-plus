<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\CommunicationResult;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Communication>
 */
class CommunicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Communication::class);
    }

    /**
     * @return list<Communication>
     */
    public function findByIncidentReport(IncidentReport $report): array
    {
        /** @var list<Communication> $result */
        $result = $this->createQueryBuilder('c')
            ->where('c.incidentReport = :report')
            ->setParameter('report', $report->getId(), 'uuid')
            ->orderBy('c.performedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<Communication>
     */
    public function findBySanction(Sanction $sanction): array
    {
        /** @var list<Communication> $result */
        $result = $this->createQueryBuilder('c')
            ->where('c.sanction = :sanction')
            ->setParameter('sanction', $sanction->getId(), 'uuid')
            ->orderBy('c.performedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countByMethod(CommunicationMethod $method): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.method = :method')
            ->setParameter('method', $method->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Builds the communication history query for the given academic year, restricted to the
     * viewer's visibility and ordered by performed date DESC.
     *
     * Visibility rules:
     *  - Global admin, centre admin, committee member or counselor → every communication of the year
     *  - Other teacher → only communications of reports they registered or tutor, and sanctions
     *    linked to a report they registered or to a group they tutor
     *
     * Supported filters:
     *   search string — student or group name (report's or sanction's), or the teacher who performed the communication
     *   type   string — 'report'|'sanction'|'' (both)
     *   result string — CommunicationResult value or '' (both)
     *
     * @param array<string, mixed> $filters
     * @return Query<null, Communication>
     */
    public function createFilteredQuery(
        AcademicYear $year,
        Teacher $viewer,
        array $filters = [],
    ): Query {
        $centre = $year->getEducationalCentre();

        $qb = $this->createQueryBuilder('c')
            ->addSelect('m', 'pb', 'r', 'rs', 'rg', 's', 'ss', 'sg')
            ->join('c.method', 'm')
            ->join('c.performedBy', 'pb')
            ->leftJoin('c.incidentReport', 'r')
            ->leftJoin('r.student', 'rs')
            ->leftJoin('r.group', 'rg')
            ->leftJoin('c.sanction', 's')
            ->leftJoin('s.student', 'ss')
            ->leftJoin('s.group', 'sg')
            ->where('r.academicYear = :year OR s.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('c.performedAt', 'DESC');

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->andX(
                        'c.incidentReport IS NOT NULL',
                        $qb->expr()->orX('r.registeredBy = :viewer', ':viewer MEMBER OF rg.tutors'),
                    ),
                    $qb->expr()->andX(
                        'c.sanction IS NOT NULL',
                        $qb->expr()->orX(
                            ':viewer MEMBER OF sg.tutors',
                            'EXISTS (SELECT sr.id FROM ' . IncidentReport::class . ' sr WHERE sr.sanction = s AND sr.registeredBy = :viewer)',
                        ),
                    ),
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        $search = $filters['search'] ?? '';
        if (is_string($search) && $search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(rs.name.firstName) LIKE LOWER(:search)',
                    'LOWER(rs.name.lastName) LIKE LOWER(:search)',
                    'LOWER(rg.name) LIKE LOWER(:search)',
                    'LOWER(ss.name.firstName) LIKE LOWER(:search)',
                    'LOWER(ss.name.lastName) LIKE LOWER(:search)',
                    'LOWER(sg.name) LIKE LOWER(:search)',
                    'LOWER(pb.name.firstName) LIKE LOWER(:search)',
                    'LOWER(pb.name.lastName) LIKE LOWER(:search)',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        $type = $filters['type'] ?? '';
        if ($type === 'report') {
            $qb->andWhere('c.incidentReport IS NOT NULL');
        } elseif ($type === 'sanction') {
            $qb->andWhere('c.sanction IS NOT NULL');
        }

        $result = CommunicationResult::tryFrom(is_string($filters['result'] ?? null) ? $filters['result'] : '');
        if ($result !== null) {
            $qb->andWhere('c.result = :result')
               ->setParameter('result', $result);
        }

        return $qb->getQuery();
    }
}
