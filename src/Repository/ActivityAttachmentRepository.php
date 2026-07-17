<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActivityAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityAttachment>
 */
class ActivityAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActivityAttachment::class);
    }

    public function findById(string $id): ?ActivityAttachment
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ActivityAttachment ? $result : null;
    }
}
