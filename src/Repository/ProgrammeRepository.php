<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Programme;
use App\Entity\ProfessionalFamily;
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
     * Programmes visible to a teacher: they teach a group in it, are tutor of a group, or are head of its family.
     *
     * @return Programme[]
     */
    public function findByAcademicYearVisibleToTeacher(AcademicYear $year, Teacher $teacher, string $familyId = ''): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.professionalFamily', 'f')
            ->where('p.academicYear = :year')
            ->andWhere(
                'f.head = :teacher
                OR EXISTS (
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
            ->orderBy('f.name', 'ASC')
            ->addOrderBy('p.name', 'ASC');


        if ($familyId !== '') {
            $qb->andWhere('f.id = :family')
               ->setParameter('family', $familyId, 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return Programme[] */
    public function findByAcademicYearFilteredByFamily(AcademicYear $year, string $familyId = ''): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.professionalFamily', 'f')
            ->where('p.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('f.name', 'ASC')
            ->addOrderBy('p.name', 'ASC');

        if ($familyId !== '') {
            $qb->andWhere('f.id = :family')
               ->setParameter('family', $familyId, 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return Programme[] */
    /** @return Programme[] */
    public function findByAcademicYearOrderedByFamilyAndName(AcademicYear $year): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.professionalFamily', 'f')
            ->where('p.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('f.name', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByAcademicYearAndId(AcademicYear $year, string $id): ?Programme
    {
        return $this->createQueryBuilder('p')
            ->where('p.academicYear = :year')
            ->andWhere('p.id = :id')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Number of programmes per family, keyed by family UUID (RFC4122). Single grouped query.
     *
     * @param  ProfessionalFamily[] $families
     * @return array<string, int>
     */
    public function countByFamily(array $families): array
    {
        if ($families === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.professionalFamily) AS fid', 'COUNT(p.id) AS cnt')
            ->where('p.professionalFamily IN (:families)')
            ->setParameter('families', $families)
            ->groupBy('p.professionalFamily')
            ->getQuery()
            ->getScalarResult();

        $uuidNorm = [];
        foreach ($families as $family) {
            $rfc = $family->getId()->toRfc4122();
            $uuidNorm[$rfc]                       = $rfc;
            $uuidNorm[$family->getId()->toBinary()] = $rfc;
        }

        $map = [];
        foreach ($rows as $row) {
            $key = $uuidNorm[(string) $row['fid']] ?? (string) $row['fid'];
            $map[$key] = (int) $row['cnt'];
        }

        return $map;
    }

    /** @return Programme[] */
    public function findByFamilyOrderedByName(ProfessionalFamily $family): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.professionalFamily = :family')
            ->setParameter('family', $family->getId(), 'uuid')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByFamilyAndId(ProfessionalFamily $family, string $id): ?Programme
    {
        return $this->createQueryBuilder('p')
            ->where('p.professionalFamily = :family')
            ->andWhere('p.id = :id')
            ->setParameter('family', $family->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

}
