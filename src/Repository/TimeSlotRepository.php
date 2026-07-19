<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Entity\TimeSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimeSlot>
 */
class TimeSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeSlot::class);
    }

    public function findById(string $id): ?TimeSlot
    {
        $result = $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof TimeSlot ? $result : null;
    }

    /** @return TimeSlot[] */
    public function findByAcademicYearOrdered(AcademicYear $year): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('t.dayOfWeek', 'ASC')
            ->addOrderBy('t.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return TimeSlot[] */
    public function findByAcademicYearAndDay(AcademicYear $year, int $dayOfWeek): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.guards', 'g')
            ->addSelect('g')
            ->where('t.academicYear = :year')
            ->andWhere('t.dayOfWeek = :day')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('day', $dayOfWeek)
            ->orderBy('t.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return TimeSlot[] */
    public function findByAcademicYearOrderedWithGuards(AcademicYear $year): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.guards', 'g')
            ->addSelect('g')
            ->where('t.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('t.dayOfWeek', 'ASC')
            ->addOrderBy('t.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByAcademicYearAndId(AcademicYear $year, string $id): ?TimeSlot
    {
        $result = $this->createQueryBuilder('t')
            ->where('t.academicYear = :year')
            ->andWhere('t.id = :id')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof TimeSlot ? $result : null;
    }

    public function countByAcademicYear(AcademicYear $year): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether the teacher guards at least one time slot of the given centre/year. Uses MEMBER OF
     * (translated to an EXISTS subquery) instead of touching the EXTRA_LAZY guards collection, to
     * avoid triggering a per-call query.
     */
    public function hasGuardDutyInYear(EducationalCentre $centre, Teacher $teacher, AcademicYear $year): bool
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.academicYear = :year')
            ->andWhere(':teacher MEMBER OF t.guards')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
