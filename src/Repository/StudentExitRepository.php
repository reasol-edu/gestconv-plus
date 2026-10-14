<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\ExitReason;
use App\Entity\Student;
use App\Entity\StudentExit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<StudentExit>
 */
class StudentExitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StudentExit::class);
    }

    public function findById(string $id): ?StudentExit
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $result = $this->baseQuery()
            ->where('e.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof StudentExit ? $result : null;
    }

    public function countByReason(ExitReason $reason): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.reason = :reason')
            ->setParameter('reason', $reason->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Salidas sin regreso registrado del curso, la más antigua primero (es la más urgente).
     *
     * @return list<StudentExit>
     */
    public function findCurrent(AcademicYear $year): array
    {
        /** @var list<StudentExit> $result */
        // Se traen también los tutores de cada grupo: el listado en vivo pregunta por cada salida si
        // el docente puede registrar su regreso, y sin esto sería una consulta por grupo en cada sondeo.
        $result = $this->baseQuery()
            ->leftJoin('g.tutors', 'gt')->addSelect('gt')
            ->where('e.academicYear = :year')
            ->andWhere('e.returnedAt IS NULL')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('e.exitedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Salidas del estudiante cuyo instante de salida cae en [$from, $to), de la más reciente a
     * la más antigua.
     *
     * @return Query<null, StudentExit>
     */
    public function createStudentDayQuery(Student $student, \DateTimeImmutable $from, \DateTimeImmutable $to): Query
    {
        return $this->baseQuery()
            ->where('e.student = :student')
            ->andWhere('e.exitedAt >= :from')
            ->andWhere('e.exitedAt < :to')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.exitedAt', 'DESC')
            ->getQuery();
    }

    /**
     * Historial completo del estudiante (todos los cursos), de la más reciente a la más antigua.
     *
     * @return Query<null, StudentExit>
     */
    public function createStudentHistoryQuery(Student $student): Query
    {
        return $this->baseQuery()
            ->where('e.student = :student')
            ->setParameter('student', $student->getId(), 'uuid')
            ->orderBy('e.exitedAt', 'DESC')
            ->getQuery();
    }

    /**
     * Listado filtrable de las salidas del curso (pestaña de dirección).
     *
     * Filtros opcionales: search (estudiante o docente), groupId, reasonId, teacherId, from, to
     * (fechas `Y-m-d`, ambas incluidas), status (`out` | `returned`), sort, sortDir.
     *
     * @param array<string, mixed> $filters
     * @return Query<null, StudentExit>
     */
    public function createFilteredQuery(AcademicYear $year, array $filters = []): Query
    {
        $qb = $this->baseQuery()
            ->where('e.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid');

        foreach (['groupId' => 'g.id', 'reasonId' => 'r.id', 'teacherId' => 'a.id'] as $key => $field) {
            $value = $filters[$key] ?? '';
            if (is_string($value) && $value !== '' && Uuid::isValid($value)) {
                $qb->andWhere($field . ' = :' . $key)->setParameter($key, $value, 'uuid');
            }
        }

        $search = $filters['search'] ?? '';
        if (is_string($search) && $search !== '') {
            $qb->andWhere($qb->expr()->orX(
                'UNACCENT(LOWER(s.name.firstName)) LIKE UNACCENT(LOWER(:search))',
                'UNACCENT(LOWER(s.name.lastName)) LIKE UNACCENT(LOWER(:search))',
                'UNACCENT(LOWER(a.name.firstName)) LIKE UNACCENT(LOWER(:search))',
                'UNACCENT(LOWER(a.name.lastName)) LIKE UNACCENT(LOWER(:search))',
            ))->setParameter('search', '%' . $search . '%');
        }

        $from = $this->parseDate($filters['from'] ?? null);
        if ($from !== null) {
            $qb->andWhere('e.exitedAt >= :from')->setParameter('from', $from);
        }
        $to = $this->parseDate($filters['to'] ?? null);
        if ($to !== null) {
            $qb->andWhere('e.exitedAt < :to')->setParameter('to', $to->modify('+1 day'));
        }

        $status = $filters['status'] ?? '';
        if ($status === 'out') {
            $qb->andWhere('e.returnedAt IS NULL');
        } elseif ($status === 'returned') {
            $qb->andWhere('e.returnedAt IS NOT NULL');
        }

        $sortDirRaw = $filters['sortDir'] ?? 'desc';
        $sortDir    = is_string($sortDirRaw) && strtolower($sortDirRaw) === 'asc' ? 'ASC' : 'DESC';
        $sort       = is_string($filters['sort'] ?? null) ? $filters['sort'] : '';

        match ($sort) {
            'student' => $qb->orderBy('s.name.lastName', $sortDir)->addOrderBy('s.name.firstName', $sortDir),
            'teacher' => $qb->orderBy('a.name.lastName', $sortDir)->addOrderBy('a.name.firstName', $sortDir),
            'group'   => $qb->orderBy('g.name', $sortDir),
            'reason'  => $qb->orderBy('r.name', $sortDir),
            default   => $qb->orderBy('e.exitedAt', $sort === 'exitedAt' ? $sortDir : 'DESC'),
        };

        return $qb->getQuery();
    }

    private function baseQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('e')
            ->addSelect('s', 'g', 'r', 'a', 'rb')
            ->join('e.student', 's')
            ->join('e.group', 'g')
            ->join('e.reason', 'r')
            ->join('e.authorizedBy', 'a')
            ->leftJoin('e.returnedBy', 'rb');
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }
}
