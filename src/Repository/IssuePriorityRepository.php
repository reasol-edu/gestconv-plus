<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\IssuePriority;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<IssuePriority> */
class IssuePriorityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IssuePriority::class);
    }

    /**
     * Las prioridades del centro, de más a menos importante.
     *
     * @return list<IssuePriority>
     */
    public function findByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<IssuePriority> $result */
        $result = $this->createQueryBuilder('p')
            ->where('p.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findByCentreAndId(EducationalCentre $centre, string $id): ?IssuePriority
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        /** @var IssuePriority|null $result */
        $result = $this->createQueryBuilder('p')
            ->where('p.educationalCentre = :centre')
            ->andWhere('p.id = :id')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function nextPosition(EducationalCentre $centre): int
    {
        return 1 + (int) $this->createQueryBuilder('p')
            ->select('COALESCE(MAX(p.position), -1)')
            ->where('p.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
