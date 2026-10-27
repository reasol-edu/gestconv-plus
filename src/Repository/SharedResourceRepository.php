<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ResourceCategory;
use App\Entity\SharedResource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SharedResource>
 */
class SharedResourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedResource::class);
    }

    /**
     * Los recursos del centro, con su categoría, ordenados por categoría y nombre.
     *
     * @return list<SharedResource>
     */
    public function findByCentreOrdered(EducationalCentre $centre, bool $onlyActive = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('c')
            ->join('r.category', 'c')
            ->where('c.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('r.name', 'ASC');

        if ($onlyActive) {
            $qb->andWhere('r.active = true')->andWhere('c.active = true');
        }

        /** @var list<SharedResource> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function findById(string $id): ?SharedResource
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('r')
            ->addSelect('c')
            ->join('r.category', 'c')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SharedResource ? $result : null;
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?SharedResource
    {
        $resource = $this->findById($id);

        return $resource !== null && $resource->getCategory()->getEducationalCentre()->getId()->equals($centre->getId()) ? $resource : null;
    }

    public function countByCategory(ResourceCategory $category): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.category = :category')
            ->setParameter('category', $category->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
