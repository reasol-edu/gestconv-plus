<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SanctionTaskAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SanctionTaskAttachment>
 */
class SanctionTaskAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SanctionTaskAttachment::class);
    }

    public function findById(string $id): ?SanctionTaskAttachment
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SanctionTaskAttachment ? $result : null;
    }
}
