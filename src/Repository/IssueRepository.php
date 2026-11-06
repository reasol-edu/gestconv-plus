<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Issue> */
class IssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Issue::class);
    }

    public function findByCentreAndId(EducationalCentre $centre, string $id): ?Issue
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        /** @var Issue|null $result */
        $result = $this->createQueryBuilder('i')
            ->where('i.educationalCentre = :centre')
            ->andWhere('i.id = :id')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function findByCentreAndNumber(EducationalCentre $centre, int $number): ?Issue
    {
        /** @var Issue|null $result */
        $result = $this->createQueryBuilder('i')
            ->where('i.educationalCentre = :centre')
            ->andWhere('i.number = :number')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function nextNumber(EducationalCentre $centre): int
    {
        return 1 + (int) $this->createQueryBuilder('i')
            ->select('COALESCE(MAX(i.number), 0)')
            ->where('i.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByType(IssueType $type): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.type = :type')
            ->setParameter('type', $type->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByPriority(IssuePriority $priority): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.priority = :priority')
            ->setParameter('priority', $priority->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
