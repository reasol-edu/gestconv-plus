<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ExitReason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ExitReason>
 */
class ExitReasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExitReason::class);
    }

    /**
     * @return list<ExitReason>
     */
    public function findByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<ExitReason> $result */
        $result = $this->createQueryBuilder('r')
            ->where('r.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('r.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<ExitReason>
     */
    public function findActiveByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<ExitReason> $result */
        $result = $this->createQueryBuilder('r')
            ->where('r.educationalCentre = :centre')
            ->andWhere('r.active = true')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('r.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?ExitReason
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ExitReason ? $result : null;
    }

    public function countByCentre(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
