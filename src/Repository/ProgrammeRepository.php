<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Programme;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Programme>
 */
class ProgrammeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Programme::class);
    }

    /**
     * Programmes visible to a teacher: they teach a group in it or are tutor of a group.
     *
     * @return Programme[]
     */
    public function findByAcademicYearVisibleToTeacher(AcademicYear $year, Teacher $teacher): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.academicYear = :year')
            ->andWhere(
                'EXISTS (
                    SELECT 1 FROM App\Entity\Group g
                    JOIN g.programmeYear py
                    WHERE py.programme = p AND :teacher MEMBER OF g.tutors
                )
                OR EXISTS (
                    SELECT 1 FROM App\Entity\Group g2
                    JOIN g2.programmeYear py2
                    WHERE py2.programme = p AND :teacher MEMBER OF g2.teachers
                )'
            )
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Programme[] */
    public function findByAcademicYearOrdered(AcademicYear $year): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByAcademicYearAndId(AcademicYear $year, string $id): ?Programme
    {
        $result = $this->createQueryBuilder('p')
            ->where('p.academicYear = :year')
            ->andWhere('p.id = :id')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Programme ? $result : null;
    }
}
