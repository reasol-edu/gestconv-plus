<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CommunicationMethod;
use App\Entity\EducationalCentre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommunicationMethod>
 */
class CommunicationMethodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommunicationMethod::class);
    }

    /**
     * @return list<CommunicationMethod>
     */
    public function findByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<CommunicationMethod> $result */
        $result = $this->createQueryBuilder('m')
            ->where('m.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return list<CommunicationMethod>
     */
    public function findActiveByCentreOrdered(EducationalCentre $centre): array
    {
        /** @var list<CommunicationMethod> $result */
        $result = $this->createQueryBuilder('m')
            ->where('m.educationalCentre = :centre')
            ->andWhere('m.active = true')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findById(string $id): ?CommunicationMethod
    {
        $result = $this->createQueryBuilder('m')
            ->where('m.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof CommunicationMethod ? $result : null;
    }

    public function countByCentre(EducationalCentre $centre): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
