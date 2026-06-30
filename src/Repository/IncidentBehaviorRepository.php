<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\IncidentBehavior;
use App\Entity\IncidentBehaviorCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IncidentBehavior>
 */
class IncidentBehaviorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentBehavior::class);
    }

    /**
     * Returns all behaviors for the centre ordered by category position, then behavior position.
     *
     * @return list<IncidentBehavior>
     */
    public function findByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<IncidentBehavior> $result */
        $result = $this->createQueryBuilder('b')
            ->join('b.category', 'c')
            ->where('b.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('b.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Returns only active behaviors for the centre, ordered by category then behavior position.
     *
     * @return list<IncidentBehavior>
     */
    public function findByCentreActive(EducationalCentre $centre): array
    {
        /** @var list<IncidentBehavior> $result */
        $result = $this->createQueryBuilder('b')
            ->join('b.category', 'c')
            ->where('b.educationalCentre = :centre')
            ->andWhere('b.active = true')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('b.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<IncidentBehavior>
     */
    public function findByCategoryOrdered(IncidentBehaviorCategory $category): array
    {
        /** @var list<IncidentBehavior> $result */
        $result = $this->createQueryBuilder('b')
            ->where('b.category = :category')
            ->setParameter('category', $category->getId(), 'uuid')
            ->orderBy('b.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?IncidentBehavior
    {
        $result = $this->createQueryBuilder('b')
            ->where('b.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof IncidentBehavior ? $result : null;
    }

    public function countByCentre(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByCategory(IncidentBehaviorCategory $category): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.category = :category')
            ->setParameter('category', $category->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
