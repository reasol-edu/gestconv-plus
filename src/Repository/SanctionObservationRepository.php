<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Sanction;
use App\Entity\SanctionObservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SanctionObservation>
 */
class SanctionObservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SanctionObservation::class);
    }

    /**
     * @return list<SanctionObservation>
     */
    public function findBySanction(Sanction $sanction): array
    {
        /** @var list<SanctionObservation> $result */
        $result = $this->createQueryBuilder('o')
            ->where('o.sanction = :sanction')
            ->setParameter('sanction', $sanction->getId(), 'uuid')
            ->orderBy('o.registeredAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?SanctionObservation
    {
        $result = $this->createQueryBuilder('o')
            ->where('o.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SanctionObservation ? $result : null;
    }
}
