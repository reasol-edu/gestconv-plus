<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ResourceCategory;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ResourceCategory>
 */
class ResourceCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResourceCategory::class);
    }

    /**
     * @return list<ResourceCategory>
     */
    public function findByCentreOrdered(EducationalCentre $centre, bool $onlyActive = false): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('c.name', 'ASC');

        if ($onlyActive) {
            $qb->andWhere('c.active = true');
        }

        /** @var list<ResourceCategory> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Las categorías del centro que gestiona el docente.
     *
     * @return list<ResourceCategory>
     */
    public function findManagedBy(EducationalCentre $centre, Teacher $teacher): array
    {
        /** @var list<ResourceCategory> $result */
        $result = $this->createQueryBuilder('c')
            ->join('c.managers', 'm')
            ->where('c.educationalCentre = :centre')
            ->andWhere('m.id = :teacher')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?ResourceCategory
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ResourceCategory ? $result : null;
    }

    public function findByIdAndCentre(string $id, EducationalCentre $centre): ?ResourceCategory
    {
        $category = $this->findById($id);

        return $category !== null && $category->getEducationalCentre()->getId()->equals($centre->getId()) ? $category : null;
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
