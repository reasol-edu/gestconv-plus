<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyNoteType>
 */
class DailyNoteTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyNoteType::class);
    }

    /**
     * @return list<DailyNoteType>
     */
    public function findByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<DailyNoteType> $result */
        $result = $this->createQueryBuilder('t')
            ->where('t.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('t.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<DailyNoteType>
     */
    public function findByCentreActive(EducationalCentre $centre): array
    {
        /** @var list<DailyNoteType> $result */
        $result = $this->createQueryBuilder('t')
            ->where('t.educationalCentre = :centre')
            ->andWhere('t.active = true')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('t.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?DailyNoteType
    {
        $result = $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof DailyNoteType ? $result : null;
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
