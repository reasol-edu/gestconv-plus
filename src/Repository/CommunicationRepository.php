<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Communication;
use App\Entity\CommunicationMethod;
use App\Entity\IncidentReport;
use App\Entity\Sanction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
