<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\ResourceBooking;
use App\Entity\ResourceBookingStatus;
use App\Entity\ResourceCategory;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Service\ResourceBookingFilters;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ResourceBooking>
 */
class ResourceBookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResourceBooking::class);
    }

    public function findById(string $id): ?ResourceBooking
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->baseQueryBuilder()
            ->where('b.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ResourceBooking ? $result : null;
    }

    /**
     * Las reservas de una serie periódica, por fecha.
     *
     * @return list<ResourceBooking>
     */
    public function findBySeries(Uuid $seriesId): array
    {
        /** @var list<ResourceBooking> $result */
        $result = $this->baseQueryBuilder()
            ->where('b.seriesId = :series')
            ->setParameter('series', $seriesId, 'uuid')
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->preload($result);
    }

    /**
     * Reservas que ocupan (pendientes o aprobadas) un recurso entre dos fechas, con sus tramos y docente: para
     * detectar solapes sin una consulta por fecha.
     *
     * @return list<ResourceBooking>
     */
    public function findOccupyingBetween(SharedResource $resource, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<ResourceBooking> $result */
        $result = $this->baseQueryBuilder()
            ->where('b.resource = :resource')
            ->andWhere('b.date >= :from')
            ->andWhere('b.date <= :to')
            ->andWhere('b.status IN (:occupying)')
            ->setParameter('resource', $resource->getId(), 'uuid')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->setParameter('occupying', ResourceBookingStatus::occupyingValues())
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->preload($result);
    }

    /**
     * Las reservas de un rango de fechas para el calendario (sin las rechazadas), con los filtros del usuario.
     *
     * @return list<ResourceBooking>
     */
    public function findForCalendar(AcademicYear $year, \DateTimeImmutable $from, \DateTimeImmutable $to, ResourceBookingFilters $filters): array
    {
        $qb = $this->baseQueryBuilder()
            ->where('b.academicYear = :year')
            ->andWhere('b.date >= :from')
            ->andWhere('b.date <= :to')
            ->andWhere('b.status IN (:occupying)')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->setParameter('occupying', ResourceBookingStatus::occupyingValues())
            ->orderBy('b.date', 'ASC');

        $this->applyFilters($qb, $filters);

        /** @var list<ResourceBooking> $result */
        $result = $qb->getQuery()->getResult();

        return $this->preload($result);
    }

    /**
     * Listado paginable. Con `$viewer` solo salen sus reservas y las de las categorías que gestiona
     * (`$managedCategories`); sin él, todas (dirección y administradores).
     *
     * @param list<ResourceCategory> $managedCategories
     * @return Query<null, ResourceBooking>
     */
    public function createListQuery(AcademicYear $year, ResourceBookingFilters $filters, ?Teacher $viewer = null, array $managedCategories = []): Query
    {
        $qb = $this->baseQueryBuilder()
            ->where('b.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('b.date', 'ASC')
            ->addOrderBy('b.id', 'ASC');

        if ($viewer !== null) {
            if ($managedCategories === []) {
                $qb->andWhere('b.teacher = :viewer');
            } else {
                $qb->andWhere('b.teacher = :viewer OR c.id IN (' . $this->uuidList($qb, 'managed', array_map(static fn (ResourceCategory $c): Uuid => $c->getId(), $managedCategories)) . ')');
            }
            $qb->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        $this->applyFilters($qb, $filters);

        return $qb->getQuery();
    }

    /** @return Query<null, ResourceBooking> */
    public function findNoneQuery(): Query
    {
        return $this->baseQueryBuilder()->where('1 = 0')->getQuery();
    }

    /**
     * Rellena tramos y grupos de las reservas de una página (dos consultas en total, no una por reserva).
     *
     * @param iterable<ResourceBooking> $bookings
     * @return list<ResourceBooking>
     */
    public function preload(iterable $bookings): array
    {
        $list = [];
        foreach ($bookings as $booking) {
            $list[] = $booking;
        }

        if ($list === []) {
            return [];
        }

        $ids = array_map(static fn (ResourceBooking $b): Uuid => $b->getId(), $list);
        foreach (['timeSlots' => 'ts', 'groups' => 'g'] as $association => $alias) {
            $qb = $this->getEntityManager()->createQueryBuilder()
                ->select('b', $alias)
                ->from(ResourceBooking::class, 'b')
                ->leftJoin('b.' . $association, $alias);
            $qb->where('b.id IN (' . $this->uuidList($qb, 'ids', $ids) . ')')
                ->getQuery()
                ->getResult();
        }

        return $list;
    }

    /**
     * Solicitudes pendientes de validar en las categorías dadas (todas las del curso si es null).
     *
     * @param list<ResourceCategory>|null $categories
     * @return list<ResourceBooking>
     */
    public function findPendingForCategories(AcademicYear $year, ?array $categories): array
    {
        if ($categories === []) {
            return [];
        }

        $qb = $this->baseQueryBuilder()
            ->where('b.academicYear = :year')
            ->andWhere('b.status = :pending')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('pending', ResourceBookingStatus::Pending->value)
            ->orderBy('b.date', 'ASC');

        if ($categories !== null) {
            $qb->andWhere('c.id IN (' . $this->uuidList($qb, 'categories', array_map(static fn (ResourceCategory $c): Uuid => $c->getId(), $categories)) . ')');
        }

        /** @var list<ResourceBooking> $result */
        $result = $qb->getQuery()->getResult();

        return $this->preload($result);
    }

    /**
     * Reservas del docente cuya resolución (aprobada o rechazada) aún no ha visto.
     *
     * @return list<ResourceBooking>
     */
    public function findUnseenResolutionsFor(Teacher $teacher, AcademicYear $year): array
    {
        /** @var list<ResourceBooking> $result */
        $result = $this->baseQueryBuilder()
            ->where('b.academicYear = :year')
            ->andWhere('b.teacher = :teacher')
            ->andWhere('b.resolutionSeen = false')
            ->andWhere('b.status IN (:resolved)')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->setParameter('resolved', [ResourceBookingStatus::Approved->value, ResourceBookingStatus::Rejected->value])
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->preload($result);
    }

    public function markResolutionsSeen(Teacher $teacher, AcademicYear $year): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->update(ResourceBooking::class, 'b')
            ->set('b.resolutionSeen', ':seen')
            ->where('b.teacher = :teacher')
            ->andWhere('b.academicYear = :year')
            ->andWhere('b.resolutionSeen = false')
            ->setParameter('seen', true)
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->execute();
    }

    public function countByResource(SharedResource $resource): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.resource = :resource')
            ->setParameter('resource', $resource->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByCategory(ResourceCategory $category): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->join('b.resource', 'r')
            ->where('r.category = :category')
            ->setParameter('category', $category->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Enlaza cada UUID por separado con el tipo 'uuid' y devuelve los marcadores para un IN (…): pasar el array
     * entero hace que Doctrine infiera un tipo genérico que se salta la conversión de UuidType y no encuentra filas.
     *
     * @param list<Uuid> $uuids
     */
    private function uuidList(QueryBuilder $qb, string $prefix, array $uuids): string
    {
        $placeholders = [];
        foreach ($uuids as $i => $uuid) {
            $placeholders[] = ':' . $prefix . $i;
            $qb->setParameter($prefix . $i, $uuid, 'uuid');
        }

        return implode(', ', $placeholders);
    }

    private function baseQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('b')
            ->addSelect('r', 'c', 't')
            ->join('b.resource', 'r')
            ->join('r.category', 'c')
            ->join('b.teacher', 't');
    }

    private function applyFilters(QueryBuilder $qb, ResourceBookingFilters $filters): void
    {
        if ($filters->categoryId !== '' && Uuid::isValid($filters->categoryId)) {
            $qb->andWhere('c.id = :fCategory')->setParameter('fCategory', $filters->categoryId, 'uuid');
        }
        if ($filters->resourceId !== '' && Uuid::isValid($filters->resourceId)) {
            $qb->andWhere('r.id = :fResource')->setParameter('fResource', $filters->resourceId, 'uuid');
        }
        if ($filters->teacherId !== '' && Uuid::isValid($filters->teacherId)) {
            $qb->andWhere('t.id = :fTeacher')->setParameter('fTeacher', $filters->teacherId, 'uuid');
        }
        if ($filters->groupId !== '' && Uuid::isValid($filters->groupId)) {
            $qb->andWhere('EXISTS (SELECT 1 FROM ' . ResourceBooking::class . ' fb JOIN fb.groups fg WHERE fb = b AND fg.id = :fGroup)')
                ->setParameter('fGroup', $filters->groupId, 'uuid');
        }
        if ($filters->status !== '' && ResourceBookingStatus::tryFrom($filters->status) !== null) {
            $qb->andWhere('b.status = :fStatus')->setParameter('fStatus', $filters->status);
        }
        if ($filters->dateFrom !== null) {
            $qb->andWhere('b.date >= :fFrom')->setParameter('fFrom', $filters->dateFrom, Types::DATE_IMMUTABLE);
        }
        if ($filters->dateTo !== null) {
            $qb->andWhere('b.date <= :fTo')->setParameter('fTo', $filters->dateTo, Types::DATE_IMMUTABLE);
        }
    }
}
