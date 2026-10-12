<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EducationalCentre>
 */
class EducationalCentreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EducationalCentre::class);
    }

    public function findByCode(string $code): ?EducationalCentre
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findById(string $id): ?EducationalCentre
    {
        $result = $this->createQueryBuilder('ec')
            ->where('ec.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof EducationalCentre ? $result : null;
    }

    public function findByIdWithActiveYear(string $id): ?EducationalCentre
    {
        $result = $this->createQueryBuilder('ec')
            ->leftJoin('ec.activeAcademicYear', 'ay')
            ->addSelect('ay')
            ->where('ec.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof EducationalCentre ? $result : null;
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('ec')
            ->select('COUNT(ec.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return EducationalCentre[] */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('ec')
            ->orderBy('ec.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return EducationalCentre[] */
    public function findAllWithActiveYear(): array
    {
        return $this->createQueryBuilder('ec')
            ->leftJoin('ec.activeAcademicYear', 'ay')
            ->addSelect('ay')
            ->orderBy('ec.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Query<null, EducationalCentre> */
    public function createAllWithActiveYearQuery(): Query
    {
        return $this->createAllWithActiveYearFilteredQuery();
    }

    /** Columnas ordenables: clave pública => campo DQL (allowlist contra inyección). */
    private const SORTABLE = [
        'code' => 'ec.code',
        'name' => 'ec.name',
        'city' => 'ec.city',
    ];

    /** @return Query<null, EducationalCentre> */
    public function createAllWithActiveYearFilteredQuery(string $search = '', string $sort = '', string $sortDir = 'asc'): Query
    {
        $qb = $this->createQueryBuilder('ec')
            ->leftJoin('ec.activeAcademicYear', 'ay')
            ->addSelect('ay');

        $dir = strtolower($sortDir) === 'desc' ? 'DESC' : 'ASC';
        $qb->orderBy(self::SORTABLE[$sort] ?? 'ec.name', isset(self::SORTABLE[$sort]) ? $dir : 'ASC');

        if ($search !== '') {
            $q = '%' . $search . '%';
            $qb->where(
                $qb->expr()->orX(
                    'UNACCENT(LOWER(ec.name)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(ec.code)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(ec.city)) LIKE UNACCENT(LOWER(:q))',
                )
            )->setParameter('q', $q);
        }

        return $qb->getQuery();
    }

    /** @return EducationalCentre[] */
    public function findAccessibleByTeacher(Teacher $teacher): array
    {
        if ($teacher->isAdmin()) {
            return $this->findAllOrderedByName();
        }

        // Una sola consulta (se ejecuta en cada petición desde el menú): el docente accede al centro si
        // es administrador suyo, está en el curso activo o da clase / tutoriza algún grupo de cualquier curso.
        /** @var list<EducationalCentre> $centres */
        $centres = $this->createQueryBuilder('ec')
            ->where('EXISTS (SELECT 1 FROM ' . EducationalCentre::class . ' ecA JOIN ecA.admins adm
                             WHERE ecA.id = ec.id AND adm.id = :tid)')
            ->orWhere('EXISTS (SELECT 1 FROM ' . AcademicYear::class . ' ayT JOIN ayT.teachers tch
                               WHERE ayT.id = IDENTITY(ec.activeAcademicYear) AND tch.id = :tid)')
            ->orWhere('EXISTS (SELECT 1 FROM ' . Group::class . ' g
                               JOIN g.course c JOIN c.academicYear ayG
                               LEFT JOIN g.groupTeachers ggt LEFT JOIN g.tutors gtu
                               WHERE ayG.educationalCentre = ec AND (ggt.teacher = :tid OR gtu.id = :tid))')
            ->setParameter('tid', $teacher->getId(), 'uuid')
            ->orderBy('ec.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $centres;
    }
}
