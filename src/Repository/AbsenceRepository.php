<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
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

    /** @return list<Absence> */
    public function findWithDatesForAcademicYear(AcademicYear $year): array
    {
        /** @var list<Absence> $result */
        $result = $this->createQueryBuilder('a')
            ->addSelect('t')
            ->join('a.teacher', 't')
            ->where('a.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** @return list<Teacher> */
    public function findTeachersAbsentOn(AcademicYear $year, \DateTimeImmutable $date): array
    {
        $em = $this->getEntityManager();

        /** @var list<string> $teacherIds */
        $teacherIds = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.teacher)')
            ->where('a.academicYear = :year')
            ->andWhere('a.startDate <= :date')
            ->andWhere('a.endDate >= :date')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleColumnResult();

        if ($teacherIds === []) {
            return [];
        }

        /** @var list<Teacher> $result */
        $result = $em->createQueryBuilder()
            ->select('t')
            ->from(Teacher::class, 't')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', array_unique($teacherIds))
            ->orderBy('t.name.lastName', 'ASC')
            ->addOrderBy('t.name.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
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

    /**
     * @param array{
     *   dateFrom?: string,
     *   dateTo?: string,
     *   teacherId?: string,
     * } $filters
     * @return Query<null, Absence>
     */
    public function createFilteredQuery(AcademicYear $year, array $filters = []): Query
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('t')
            ->join('a.teacher', 't')
            ->where('a.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('a.endDate', 'DESC');

        if (!empty($filters['teacherId'])) {
            $qb->andWhere('t.id = :teacher')->setParameter('teacher', $filters['teacherId'], 'uuid');
        }

        if (!empty($filters['dateFrom'])) {
            try {
                $from = new \DateTimeImmutable($filters['dateFrom']);
                $qb->andWhere('a.endDate >= :dateFrom')->setParameter('dateFrom', $from);
            } catch (\Exception) {
            }
        }

        if (!empty($filters['dateTo'])) {
            try {
                $to = new \DateTimeImmutable($filters['dateTo']);
                $qb->andWhere('a.startDate <= :dateTo')->setParameter('dateTo', $to);
            } catch (\Exception) {
            }
        }

        return $qb->getQuery();
    }
}
