<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\IncidentBehaviorCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IncidentBehaviorCategory>
 */
class IncidentBehaviorCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentBehaviorCategory::class);
    }

    /**
     * @return list<IncidentBehaviorCategory>
     */
    public function findByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<IncidentBehaviorCategory> $result */
        $result = $this->createQueryBuilder('c')
            ->where('c.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?IncidentBehaviorCategory
    {
        $result = $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof IncidentBehaviorCategory ? $result : null;
    }

    public function countByCentre(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
