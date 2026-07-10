<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\LocationOption;
use App\Entity\LocationOptionCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocationOption>
 */
class LocationOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocationOption::class);
    }

    /**
     * Returns all location options for the centre ordered by category position, then option position.
     *
     * @return list<LocationOption>
     */
    public function findByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<LocationOption> $result */
        $result = $this->createQueryBuilder('o')
            ->addSelect('c')
            ->join('o.category', 'c')
            ->where('o.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('o.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Returns only active location options for the centre, ordered by category then option position.
     *
     * @return list<LocationOption>
     */
    public function findByCentreActive(EducationalCentre $centre): array
    {
        /** @var list<LocationOption> $result */
        $result = $this->createQueryBuilder('o')
            ->addSelect('c')
            ->join('o.category', 'c')
            ->where('o.educationalCentre = :centre')
            ->andWhere('o.active = true')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('o.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<LocationOption>
     */
    public function findByCategoryOrdered(LocationOptionCategory $category): array
    {
        /** @var list<LocationOption> $result */
        $result = $this->createQueryBuilder('o')
            ->where('o.category = :category')
            ->setParameter('category', $category->getId(), 'uuid')
            ->orderBy('o.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?LocationOption
    {
        $result = $this->createQueryBuilder('o')
            ->where('o.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof LocationOption ? $result : null;
    }

    public function countByCentre(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByCategory(LocationOptionCategory $category): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.category = :category')
            ->setParameter('category', $category->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Searches active location options for the centre by name (for the search-as-you-type dropdown).
     *
     * @return list<LocationOption>
     */
    public function searchActiveByCentre(EducationalCentre $centre, string $q, int $limit = 20): array
    {
        /** @var list<LocationOption> $result */
        $result = $this->createQueryBuilder('o')
            ->addSelect('c')
            ->join('o.category', 'c')
            ->where('o.educationalCentre = :centre')
            ->andWhere('o.active = true')
            ->andWhere('LOWER(o.name) LIKE LOWER(:search)')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('search', '%' . $q . '%')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('o.position', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
