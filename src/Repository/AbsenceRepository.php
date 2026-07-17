<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Absence>
 */
class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    public function findById(string $id): ?Absence
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Absence ? $result : null;
    }

    /** @return Absence[] */
    public function findByTeacherAndYearOrderedByStartDate(Teacher $teacher, AcademicYear $year): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.teacher = :teacher')
            ->andWhere('a.academicYear = :year')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('a.startDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
