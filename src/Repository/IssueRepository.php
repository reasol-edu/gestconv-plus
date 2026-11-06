<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\IssueType;
use App\Entity\Teacher;
use App\Service\IssueListFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Issue> */
class IssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Issue::class);
    }

    public function findByCentreAndId(EducationalCentre $centre, string $id): ?Issue
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        /** @var Issue|null $result */
        $result = $this->createQueryBuilder('i')
            ->where('i.educationalCentre = :centre')
            ->andWhere('i.id = :id')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function findByCentreAndNumber(EducationalCentre $centre, int $number): ?Issue
    {
        /** @var Issue|null $result */
        $result = $this->createQueryBuilder('i')
            ->where('i.educationalCentre = :centre')
            ->andWhere('i.number = :number')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function nextNumber(EducationalCentre $centre): int
    {
        return 1 + (int) $this->createQueryBuilder('i')
            ->select('COALESCE(MAX(i.number), 0)')
            ->where('i.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByType(IssueType $type): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.type = :type')
            ->setParameter('type', $type->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByPriority(IssuePriority $priority): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.priority = :priority')
            ->setParameter('priority', $priority->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * El listado: las incidencias que el docente puede ver (todas las del centro si es de la dirección; si no, las que
     * registró y las de los tipos que gestiona), filtradas y ordenadas.
     *
     * @param list<string> $managedTypeIds los tipos que gestiona fuera de la dirección
     * @return Query<null, Issue>
     */
    public function createListQuery(EducationalCentre $centre, Teacher $viewer, bool $management, array $managedTypeIds, IssueListFilter $filter): Query
    {
        $qb = $this->createQueryBuilder('i')
            ->join('i.type', 't')
            ->join('i.reporter', 'r')
            ->leftJoin('i.priority', 'p')
            ->leftJoin('i.location', 'l')
            ->addSelect('t', 'r', 'p');
        $this->restrictToVisible($qb, $centre, $viewer, $management, $managedTypeIds);

        if ($filter->statuses !== []) {
            $qb->andWhere('i.status IN (:statuses)')->setParameter('statuses', $filter->statuses);
        }
        if ($filter->typeIds !== []) {
            $qb->andWhere($this->inUuids($qb, 't.id', 'typeId', $filter->typeIds));
        }
        if ($filter->priority === IssueListFilter::NO_PRIORITY) {
            $qb->andWhere('i.priority IS NULL');
        } elseif ($filter->priority !== '' && Uuid::isValid($filter->priority)) {
            $qb->andWhere('p.id = :priority')->setParameter('priority', $filter->priority, 'uuid');
        }
        if ($filter->scope === IssueListFilter::SCOPE_MINE) {
            $qb->andWhere('i.reporter = :viewer')->setParameter('viewer', $viewer->getId(), 'uuid');
        } elseif ($filter->scope === IssueListFilter::SCOPE_MANAGED) {
            $qb->andWhere('i.reporter != :viewer')->setParameter('viewer', $viewer->getId(), 'uuid');
        } elseif ($filter->scope === IssueListFilter::SCOPE_ASSIGNED) {
            $qb->andWhere('i.assignee = :viewer')->setParameter('viewer', $viewer->getId(), 'uuid');
        }
        if ($filter->overdueOnly && $filter->today !== null) {
            $qb->andWhere('i.status IN (:openStatuses)')->andWhere('i.maxDue < :today')
                ->setParameter('openStatuses', IssueStatus::openValues())
                ->setParameter('today', $filter->today->setTime(0, 0), Types::DATE_IMMUTABLE);
        }

        $search = trim($filter->search);
        if (preg_match('/^#?(\d{1,9})$/', $search, $m) === 1) {
            $qb->andWhere('i.number = :number')->setParameter('number', (int) $m[1]);
        } elseif ($search !== '') {
            $like = 'UNACCENT(LOWER(%s)) LIKE UNACCENT(LOWER(:search))';
            $qb->andWhere($qb->expr()->orX(
                sprintf($like, 'i.description'),
                sprintf($like, 't.name'),
                sprintf($like, 'l.name'),
                sprintf($like, 'r.name.firstName'),
                sprintf($like, 'r.name.lastName'),
            ))->setParameter('search', '%' . $search . '%');
        }

        $dir = $filter->sortDir === 'asc' ? 'ASC' : 'DESC';
        switch ($filter->sort) {
            case 'number':
                $qb->orderBy('i.number', $dir);

                break;
            case 'type':
                $qb->orderBy('t.name', $dir);

                break;
            case 'priority':
                // Sin prioridad al final al ordenar de más a menos importante.
                $qb->addSelect('COALESCE(p.position, 9999) AS HIDDEN prioritySort')->orderBy('prioritySort', $dir);

                break;
            case 'status':
                $qb->orderBy('i.status', $dir);

                break;
            case 'due':
                $qb->addSelect('CASE WHEN i.maxDue IS NULL THEN 1 ELSE 0 END AS HIDDEN dueMissing')->orderBy('dueMissing', 'ASC')->addOrderBy('i.maxDue', $dir);

                break;
            default:
                $qb->orderBy('i.createdAt', $dir);
        }
        $qb->addOrderBy('i.number', 'DESC');

        return $qb->getQuery();
    }

    /**
     * Cifras para la cabecera del listado, sobre lo que el docente puede ver.
     *
     * @param list<string> $managedTypeIds
     * @return array{open: int, in_progress: int, no_priority: int, overdue: int, closed: int}
     */
    public function statistics(EducationalCentre $centre, Teacher $viewer, bool $management, array $managedTypeIds, \DateTimeImmutable $today): array
    {
        $qb = $this->createQueryBuilder('i')->select('i.status AS status', 'COUNT(i.id) AS total')->groupBy('i.status');
        $this->restrictToVisible($qb, $centre, $viewer, $management, $managedTypeIds);
        /** @var list<array{status: IssueStatus, total: int|string}> $rows */
        $rows   = $qb->getQuery()->getResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['total'];
        }

        $noPriority = $this->createQueryBuilder('i')->select('COUNT(i.id)')->andWhere('i.priority IS NULL')->andWhere('i.status IN (:open)')->setParameter('open', IssueStatus::openValues());
        $this->restrictToVisible($noPriority, $centre, $viewer, $management, $managedTypeIds);

        $overdue = $this->createQueryBuilder('i')->select('COUNT(i.id)')->andWhere('i.status IN (:open)')->andWhere('i.maxDue < :today')
            ->setParameter('open', IssueStatus::openValues())
            ->setParameter('today', $today->setTime(0, 0), Types::DATE_IMMUTABLE);
        $this->restrictToVisible($overdue, $centre, $viewer, $management, $managedTypeIds);

        $closed = 0;
        foreach (IssueStatus::closedStatuses() as $status) {
            $closed += $counts[$status->value] ?? 0;
        }

        return [
            'open'        => $counts[IssueStatus::Open->value] ?? 0,
            'in_progress' => $counts[IssueStatus::InProgress->value] ?? 0,
            'no_priority' => (int) $noPriority->getQuery()->getSingleScalarResult(),
            'overdue'     => (int) $overdue->getQuery()->getSingleScalarResult(),
            'closed'      => $closed,
        ];
    }

    /**
     * Las incidencias que se pueden indicar como original al cerrar esta por duplicidad: las del centro, salvo ella misma y
     * las que se cerraron como duplicadas de ella; las más recientes primero.
     *
     * @return list<Issue>
     */
    public function findDuplicateCandidates(EducationalCentre $centre, Issue $issue, int $limit = 500): array
    {
        /** @var list<Issue> $result */
        $result = $this->createQueryBuilder('i')
            ->addSelect('t')
            ->join('i.type', 't')
            ->where('i.educationalCentre = :centre')
            ->andWhere('i.id != :self')
            ->andWhere('i.duplicateOf IS NULL OR i.duplicateOf != :self')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('self', $issue->getId(), 'uuid')
            ->orderBy('i.number', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Las incidencias del centro asociadas a una reserva, de la más antigua a la más reciente.
     *
     * @return list<Issue>
     */
    public function findWithBooking(EducationalCentre $centre): array
    {
        /** @var list<Issue> $result */
        $result = $this->createQueryBuilder('i')
            ->addSelect('t', 'r')
            ->join('i.type', 't')
            ->join('i.reporter', 'r')
            ->where('i.educationalCentre = :centre')
            ->andWhere('i.booking IS NOT NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('i.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Las incidencias abiertas del centro que han pasado su plazo máximo (para el recordatorio diario).
     *
     * @return list<Issue>
     */
    public function findOverdue(EducationalCentre $centre, \DateTimeImmutable $today): array
    {
        /** @var list<Issue> $result */
        $result = $this->createQueryBuilder('i')
            ->addSelect('t')
            ->join('i.type', 't')
            ->where('i.educationalCentre = :centre')
            ->andWhere('i.status IN (:open)')
            ->andWhere('i.maxDue < :today')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('open', IssueStatus::openValues())
            ->setParameter('today', $today->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->orderBy('i.maxDue', 'ASC')
            ->addOrderBy('i.number', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Las incidencias del curso registradas en el rango (días completos), con su tipo, prioridad y dependencia cargados,
     * para el informe de incidencias.
     *
     * @return list<Issue>
     */
    public function findForStats(EducationalCentre $centre, AcademicYear $year, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<Issue> $result */
        $result = $this->createQueryBuilder('i')
            ->addSelect('t', 'p', 'l')
            ->join('i.type', 't')
            ->leftJoin('i.priority', 'p')
            ->leftJoin('i.location', 'l')
            ->where('i.educationalCentre = :centre')
            ->andWhere('i.academicYear = :year')
            ->andWhere('i.createdAt >= :from')
            ->andWhere('i.createdAt <= :to')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('from', $from->setTime(0, 0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Cuándo se gestionó por primera vez cada incidencia registrada en el rango (primera prioridad, estado o asignación
     * distintos del alta): id (RFC 4122) => fecha. Las que aún no se han gestionado no aparecen.
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function firstManagementDates(EducationalCentre $centre, AcademicYear $year, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<\App\Entity\IssueChange> $changes */
        $changes = $this->getEntityManager()->createQueryBuilder()
            ->select('c', 'i')
            ->from(\App\Entity\IssueChange::class, 'c')
            ->join('c.issue', 'i')
            ->where('i.educationalCentre = :centre')
            ->andWhere('i.academicYear = :year')
            ->andWhere('i.createdAt >= :from')
            ->andWhere('i.createdAt <= :to')
            ->andWhere('c.field IN (:fields)')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('from', $from->setTime(0, 0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('fields', ['status', 'priority', 'assignee'])
            ->getQuery()
            ->getResult();

        $first = [];
        foreach ($changes as $change) {
            $id = $change->getIssue()->getId()->toRfc4122();
            if (!isset($first[$id]) || $change->getCreatedAt() < $first[$id]) {
                $first[$id] = $change->getCreatedAt();
            }
        }

        return $first;
    }

    /**
     * Las incidencias del centro con esos ids (para las acciones masivas).
     *
     * @param list<string> $ids
     * @return list<Issue>
     */
    public function findByCentreAndIds(EducationalCentre $centre, array $ids): array
    {
        $valid = array_values(array_filter($ids, static fn (string $id): bool => Uuid::isValid($id)));
        if ($valid === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('i')
            ->where('i.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid');
        $qb->andWhere($this->inUuids($qb, 'i.id', 'id', $valid));
        /** @var list<Issue> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /** @param list<string> $managedTypeIds */
    private function restrictToVisible(QueryBuilder $qb, EducationalCentre $centre, Teacher $viewer, bool $management, array $managedTypeIds): void
    {
        $qb->andWhere('i.educationalCentre = :centre')->setParameter('centre', $centre->getId(), 'uuid');
        if ($management) {
            return;
        }

        // Las que registró, las que tiene asignadas y las de los tipos que gestiona.
        if ($managedTypeIds === []) {
            $qb->andWhere('i.reporter = :me OR i.assignee = :me');
        } else {
            $qb->andWhere('i.reporter = :me OR i.assignee = :me OR ' . $this->inUuids($qb, 'IDENTITY(i.type)', 'managed', $managedTypeIds));
        }
        $qb->setParameter('me', $viewer->getId(), 'uuid');
    }

    /**
     * `campo IN (…)` con un parámetro uuid por valor: un único parámetro array haría que Doctrine ignorase la conversión
     * del tipo uuid (binario en MySQL y SQLite) y la consulta no encontraría nada.
     *
     * @param list<string> $ids
     */
    private function inUuids(QueryBuilder $qb, string $field, string $prefix, array $ids): string
    {
        $placeholders = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ":{$prefix}{$i}";
            $qb->setParameter("{$prefix}{$i}", Uuid::fromString($id), 'uuid');
        }

        return $field . ' IN (' . implode(', ', $placeholders) . ')';
    }
}
