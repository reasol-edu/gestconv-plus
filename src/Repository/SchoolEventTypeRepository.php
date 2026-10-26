<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\SchoolEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SchoolEventType>
 */
class SchoolEventTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolEventType::class);
    }

    /**
     * @return list<SchoolEventType>
     */
    public function findByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<SchoolEventType> $result */
        $result = $this->createQueryBuilder('t')
            ->where('t.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('t.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<SchoolEventType>
     */
    public function findActiveByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<SchoolEventType> $result */
        $result = $this->createQueryBuilder('t')
            ->where('t.educationalCentre = :centre')
            ->andWhere('t.active = true')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('t.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?SchoolEventType
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SchoolEventType ? $result : null;
    }

    public function countByCentre(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
