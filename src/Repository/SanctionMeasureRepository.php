<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\SanctionMeasure;
use App\Entity\SanctionMeasureCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SanctionMeasure>
 */
class SanctionMeasureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SanctionMeasure::class);
    }

    /**
     * @return list<SanctionMeasure>
     */
    public function findByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<SanctionMeasure> $result */
        $result = $this->createQueryBuilder('m')
            ->addSelect('c')
            ->join('m.category', 'c')
            ->where('m.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<SanctionMeasure>
     */
    public function findByCentreActive(EducationalCentre $centre): array
    {
        /** @var list<SanctionMeasure> $result */
        $result = $this->createQueryBuilder('m')
            ->addSelect('c')
            ->join('m.category', 'c')
            ->where('m.educationalCentre = :centre')
            ->andWhere('m.active = true')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<SanctionMeasure>
     */
    public function findByCategoryOrdered(SanctionMeasureCategory $category): array
    {
        /** @var list<SanctionMeasure> $result */
        $result = $this->createQueryBuilder('m')
            ->where('m.category = :category')
            ->setParameter('category', $category->getId(), 'uuid')
            ->orderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?SanctionMeasure
    {
        $result = $this->createQueryBuilder('m')
            ->where('m.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SanctionMeasure ? $result : null;
    }

    public function countByCategory(SanctionMeasureCategory $category): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.category = :category')
            ->setParameter('category', $category->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
