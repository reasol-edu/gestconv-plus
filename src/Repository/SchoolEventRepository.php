<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\SchoolEvent;
use App\Entity\SchoolEventType;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolEvent>
 */
class SchoolEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolEvent::class);
    }

    /** @return Query<null, SchoolEvent> */
    public function findNoneQuery(): Query
    {
        return $this->createQueryBuilder('se')
            ->where('1 = 0')
            ->getQuery();
    }

    public function findByAcademicYearAndId(AcademicYear $year, string $id): ?SchoolEvent
    {
        $result = $this->createQueryBuilder('se')
            ->where('se.academicYear = :year')
            ->andWhere('se.id = :id')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SchoolEvent ? $result : null;
    }

    /** Como findByAcademicYearAndId(), pero solo si la actividad la publicó el docente. */
    public function findOwnedByAcademicYearAndId(Teacher $owner, AcademicYear $year, string $id): ?SchoolEvent
    {
        $result = $this->baseQueryBuilder($year)
            ->andWhere('se.id = :id')
            ->andWhere('se.createdBy = :owner')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SchoolEvent ? $result : null;
    }

    /** @return list<SchoolEvent> */
    public function findAllForAcademicYearAndDate(AcademicYear $year, \DateTimeImmutable $date): array
    {
        return $this->preloadGroups($year, $this->baseQueryBuilder($year)
            ->andWhere('se.date = :date')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getResult());
    }

    /**
     * Las actividades del curso entre dos fechas (ambas incluidas), por orden cronológico.
     *
     * @return list<SchoolEvent>
     */
    public function findAllForAcademicYearBetween(AcademicYear $year, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->preloadGroups($year, $this->baseQueryBuilder($year)
            ->andWhere('se.date >= :from')
            ->andWhere('se.date <= :to')
            ->orderBy('se.date', 'ASC')
            ->addOrderBy('se.startTime', 'ASC')
            ->setParameter('from', $from, 'date_immutable')
            ->setParameter('to', $to, 'date_immutable')
            ->getQuery()
            ->getResult());
    }

    /** @return list<SchoolEvent> */
    public function findVisibleForTeacherAndDate(Teacher $viewer, AcademicYear $year, \DateTimeImmutable $date): array
    {
        return $this->preloadGroups($year, $this->visibleForTeacherQueryBuilder($viewer, $year)
            ->andWhere('se.date = :date')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getResult());
    }

    /** @return list<SchoolEvent> */
    public function findAllForAcademicYear(AcademicYear $year): array
    {
        return $this->preloadGroups($year, $this->baseQueryBuilder($year)->getQuery()->getResult());
    }

    /** @return list<SchoolEvent> */
    public function findVisibleForTeacherInAcademicYear(Teacher $viewer, AcademicYear $year): array
    {
        return $this->preloadGroups($year, $this->visibleForTeacherQueryBuilder($viewer, $year)->getQuery()->getResult());
    }

    /**
     * Inicializa la colección de grupos de todos los eventos con una sola consulta (quien recorre la
     * lista lee los grupos de cada evento; si no, sería una consulta por evento). No se hace con el
     * join de la propia consulta porque en la de «visibles» filtra por grupo y dejaría colecciones
     * incompletas.
     *
     * @param list<SchoolEvent> $events
     * @return list<SchoolEvent>
     */
    private function preloadGroups(AcademicYear $year, array $events): array
    {
        if (\count($events) > 1) {
            // Una consulta por colección (juntarlas en una multiplicaría las filas): grupos y acompañantes.
            foreach (['groups' => 'pg', 'companions' => 'pc'] as $association => $alias) {
                $this->createQueryBuilder('se')
                    ->select('se', $alias)
                    ->leftJoin('se.' . $association, $alias)
                    ->where('se.academicYear = :year')
                    ->setParameter('year', $year->getId(), 'uuid')
                    ->getQuery()
                    ->getResult();
            }
        }

        return $events;
    }

    /** Cuántas actividades tienen este tipo (un tipo en uso no se puede eliminar). */
    public function countByType(SchoolEventType $type): int
    {
        return (int) $this->createQueryBuilder('se')
            ->select('COUNT(se.id)')
            ->where('se.type = :type')
            ->setParameter('type', $type->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Query<null, SchoolEvent> */
    public function createFilteredQuery(
        AcademicYear $year,
        string $search = '',
        string $groupId = '',
        ?Teacher $onlyOwnedBy = null,
        string $typeId = '',
    ): Query {
        $qb = $this->baseQueryBuilder($year)
            ->orderBy('se.date', 'DESC')
            ->addOrderBy('se.startTime', 'ASC');

        // Un docente sin gestión de actividades solo lista las que ha publicado él.
        if ($onlyOwnedBy !== null) {
            $qb->andWhere('se.createdBy = :owner')
                ->setParameter('owner', $onlyOwnedBy->getId(), 'uuid');
        }

        if ($groupId !== '') {
            $qb->join('se.groups', 'fg')
                ->andWhere('fg.id = :groupId')
                ->setParameter('groupId', $groupId, 'uuid');
        }

        if ($typeId !== '') {
            $qb->andWhere('se.type = :typeId')
                ->setParameter('typeId', $typeId, 'uuid');
        }

        if ($search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'UNACCENT(LOWER(se.name)) LIKE UNACCENT(LOWER(:search))',
                    'UNACCENT(LOWER(se.description)) LIKE UNACCENT(LOWER(:search))',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        return $qb->getQuery();
    }

    private function baseQueryBuilder(AcademicYear $year): QueryBuilder
    {
        // Tipo y coordinador son a uno: se traen en la misma consulta para no hacer una por actividad.
        return $this->createQueryBuilder('se')
            ->distinct()
            ->addSelect('ty', 'co')
            ->leftJoin('se.type', 'ty')
            ->leftJoin('se.coordinator', 'co')
            ->where('se.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid');
    }

    private function visibleForTeacherQueryBuilder(Teacher $viewer, AcademicYear $year): QueryBuilder
    {
        $qb = $this->baseQueryBuilder($year);
        $qb->leftJoin('se.groups', 'g')
            ->leftJoin('g.groupTeachers', 'gt')
            ->andWhere($qb->expr()->orX(
                'se.general = true',
                'gt.teacher = :viewer',
                ':viewer MEMBER OF g.tutors',
            ))
            ->setParameter('viewer', $viewer->getId(), 'uuid');

        return $qb;
    }
}
