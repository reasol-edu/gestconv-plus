<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IncidentReport;
use App\Entity\IncidentReportObservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IncidentReportObservation>
 */
class IncidentReportObservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentReportObservation::class);
    }

    /**
     * @return list<IncidentReportObservation>
     */
    public function findByIncidentReport(IncidentReport $report): array
    {
        /** @var list<IncidentReportObservation> $result */
        $result = $this->createQueryBuilder('o')
            ->where('o.incidentReport = :report')
            ->setParameter('report', $report->getId(), 'uuid')
            ->orderBy('o.registeredAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?IncidentReportObservation
    {
        $result = $this->createQueryBuilder('o')
            ->where('o.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof IncidentReportObservation ? $result : null;
    }
}
