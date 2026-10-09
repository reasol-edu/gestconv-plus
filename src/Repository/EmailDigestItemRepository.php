<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailDigestItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailDigestItem>
 */
class EmailDigestItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailDigestItem::class);
    }

    /**
     * Lines not yet sent, oldest first, with recipient and centre loaded.
     *
     * @return list<EmailDigestItem>
     */
    public function findUnsent(): array
    {
        /** @var list<EmailDigestItem> $result */
        $result = $this->createQueryBuilder('i')
            ->addSelect('r', 'c')
            ->join('i.recipient', 'r')
            ->join('i.educationalCentre', 'c')
            ->where('i.sentAt IS NULL')
            ->orderBy('i.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** Removes lines already sent before $sentBefore and lines never sent that are older than $unsentBefore. */
    public function purge(\DateTimeImmutable $sentBefore, \DateTimeImmutable $unsentBefore): int
    {
        $sent = (int) $this->createQueryBuilder('i')
            ->delete()
            ->where('i.sentAt IS NOT NULL AND i.sentAt < :sentBefore')
            ->setParameter('sentBefore', $sentBefore)
            ->getQuery()
            ->execute();

        $unsent = (int) $this->createQueryBuilder('i')
            ->delete()
            ->where('i.sentAt IS NULL AND i.createdAt < :unsentBefore')
            ->setParameter('unsentBefore', $unsentBefore)
            ->getQuery()
            ->execute();

        return $sent + $unsent;
    }
}
