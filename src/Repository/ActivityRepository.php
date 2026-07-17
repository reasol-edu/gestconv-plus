<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\EducationalCentre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    public function findById(string $id): ?Activity
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Activity ? $result : null;
    }

    /** @return Activity[] */
    public function findWithAttachmentsOlderThan(EducationalCentre $centre, \DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('act')
            ->join('act.absence', 'ab')
            ->join('ab.academicYear', 'y')
            ->join('act.attachments', 'att')
            ->addSelect('att')
            ->where('y.educationalCentre = :centre')
            ->andWhere('act.date < :cutoff')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }
}
