<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Absence;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
