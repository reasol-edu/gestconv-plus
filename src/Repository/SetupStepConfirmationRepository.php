<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\SetupStepConfirmation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SetupStepConfirmation>
 */
class SetupStepConfirmationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SetupStepConfirmation::class);
    }

    /**
     * Las claves de los pasos confirmados a mano en el curso, con quién y cuándo (una consulta).
     *
     * @return array<string, SetupStepConfirmation>
     */
    public function findByAcademicYear(AcademicYear $year): array
    {
        /** @var list<SetupStepConfirmation> $rows */
        $rows = $this->createQueryBuilder('c')
            ->addSelect('t')
            ->leftJoin('c.confirmedBy', 't')
            ->where('c.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->getStepKey()] = $row;
        }

        return $byKey;
    }

    public function findOne(AcademicYear $year, string $stepKey): ?SetupStepConfirmation
    {
        $result = $this->createQueryBuilder('c')
            ->where('c.academicYear = :year')
            ->andWhere('c.stepKey = :key')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('key', $stepKey)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SetupStepConfirmation ? $result : null;
    }
}
