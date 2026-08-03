<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\DailyNote;
use App\Entity\DailyNoteType;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\Student;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DailyNote>
 */
class DailyNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyNote::class);
    }

    /**
     * Visibilidad: admin global o de centro ven todas las notas; el resto del profesorado solo
     * ve las que ha registrado o las de los grupos que tutoriza (igual que en partes de
     * convivencia, ver IncidentReportRepository::createFilteredQuery()).
     *
     * Filtros admitidos (todos opcionales): search, ownOnly, tutoringOnly, groupId, studentId,
     * typeId, sort, sortDir.
     *
     * @param array<string, mixed> $filters
     * @return Query<null, DailyNote>
     */
    public function createFilteredQuery(
        EducationalCentre $centre,
        Teacher $viewer,
        AcademicYear $year,
        array $filters = [],
    ): Query {
        $qb = $this->createQueryBuilder('n')
            ->addSelect('g', 's', 't', 'ty')
            ->join('n.group', 'g')
            ->join('n.student', 's')
            ->join('n.registeredBy', 't')
            ->join('n.type', 'ty')
            ->where('n.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid');

        $hasFullAccess = $viewer->isAdmin() || $centre->getAdmins()->contains($viewer);
        if (!$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'n.registeredBy = :viewer',
                    ':viewer MEMBER OF g.tutors',
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        $ownOnly = $filters['ownOnly'] ?? false;
        if ($ownOnly === true) {
            $qb->andWhere('n.registeredBy = :viewerOwn')
               ->setParameter('viewerOwn', $viewer->getId(), 'uuid');
        }

        $tutoringOnly = $filters['tutoringOnly'] ?? false;
        if ($tutoringOnly === true) {
            $qb->andWhere(':viewerTutor MEMBER OF g.tutors')
               ->setParameter('viewerTutor', $viewer->getId(), 'uuid');
        }

        $groupId = $filters['groupId'] ?? '';
        if (is_string($groupId) && $groupId !== '') {
            $qb->andWhere('g.id = :groupId')
               ->setParameter('groupId', $groupId, 'uuid');
        }

        $studentId = $filters['studentId'] ?? '';
        if (is_string($studentId) && $studentId !== '') {
            $qb->andWhere('n.student = :studentId')
               ->setParameter('studentId', $studentId, 'uuid');
        }

        $typeId = $filters['typeId'] ?? '';
        if (is_string($typeId) && $typeId !== '') {
            $qb->andWhere('ty.id = :typeId')
               ->setParameter('typeId', $typeId, 'uuid');
        }

        $search = $filters['search'] ?? '';
        if (is_string($search) && $search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(s.name.firstName) LIKE LOWER(:search)',
                    'LOWER(s.name.lastName) LIKE LOWER(:search)',
                    'LOWER(t.name.firstName) LIKE LOWER(:search)',
                    'LOWER(t.name.lastName) LIKE LOWER(:search)',
                    'LOWER(g.name) LIKE LOWER(:search)',
                    'LOWER(n.observations) LIKE LOWER(:search)',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        $sortDirRaw = $filters['sortDir'] ?? 'desc';
        $sortDir    = is_string($sortDirRaw) && strtolower($sortDirRaw) === 'asc' ? 'ASC' : 'DESC';

        $sortRaw = $filters['sort'] ?? '';
        $sort    = is_string($sortRaw) ? $sortRaw : '';

        match ($sort) {
            'student' => $qb->orderBy('s.name.lastName', $sortDir)->addOrderBy('s.name.firstName', $sortDir),
            'teacher' => $qb->orderBy('t.name.lastName', $sortDir)->addOrderBy('t.name.firstName', $sortDir),
            'group'   => $qb->orderBy('g.name', $sortDir),
            default   => $qb->orderBy('n.occurredAt', 'DESC'),
        };

        return $qb->distinct()->getQuery();
    }

    /**
     * Notas de un estudiante en el curso, de más reciente a más antigua (para la ficha del
     * alumno, sin restricción de visibilidad — la propia ficha ya está protegida aparte).
     *
     * @return Query<null, DailyNote>
     */
    public function createStudentHistoryQuery(Student $student, AcademicYear $year): Query
    {
        return $this->createQueryBuilder('n')
            ->addSelect('t', 'ty')
            ->join('n.registeredBy', 't')
            ->join('n.type', 'ty')
            ->where('n.student = :student')
            ->andWhere('n.academicYear = :year')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('n.occurredAt', 'DESC')
            ->getQuery();
    }

    /**
     * Historial paginable de un tipo concreto para un estudiante, de más reciente a más antigua.
     *
     * @return Query<null, DailyNote>
     */
    public function createHistoryQuery(Student $student, DailyNoteType $type, AcademicYear $year): Query
    {
        return $this->createQueryBuilder('n')
            ->addSelect('t')
            ->join('n.registeredBy', 't')
            ->where('n.student = :student')
            ->andWhere('n.type = :type')
            ->andWhere('n.academicYear = :year')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('type', $type->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('n.occurredAt', 'DESC')
            ->getQuery();
    }

    public function countActiveByStudentAndType(
        Student $student,
        DailyNoteType $type,
        AcademicYear $year,
        bool $onlyActive = true,
    ): int {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.student = :student')
            ->andWhere('n.type = :type')
            ->andWhere('n.academicYear = :year')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('type', $type->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid');

        if ($onlyActive) {
            $qb->andWhere('n.active = true');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countByType(DailyNoteType $type): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.type = :type')
            ->setParameter('type', $type->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Grupos con al menos una nota visible para el visor, para el desplegable de filtro por
     * grupo del listado (mismo patrón que IncidentReportRepository::findGroupsWithReports()).
     *
     * @return Group[]
     */
    public function findGroupsWithNotes(EducationalCentre $centre, Teacher $viewer, AcademicYear $year): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('g')
            ->distinct()
            ->from(Group::class, 'g')
            ->join(DailyNote::class, 'n', 'WITH', 'n.group = g')
            ->where('n.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('g.name', 'ASC');

        $hasFullAccess = $viewer->isAdmin() || $centre->getAdmins()->contains($viewer);
        if (!$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'n.registeredBy = :viewer',
                    ':viewer MEMBER OF g.tutors',
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        /** @var Group[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Grupos con al menos una nota de un tipo concreto, visibles para tutores/administración (sin
     * la rama de "registrada por mí" de {@see findGroupsWithNotes()}: aquí solo interesan los
     * grupos donde el visor puede actuar), para el desplegable de filtro de "Listado de
     * estudiantes".
     *
     * @return Group[]
     */
    public function findGroupsWithNotesByType(EducationalCentre $centre, Teacher $viewer, AcademicYear $year, DailyNoteType $type): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('g')
            ->distinct()
            ->from(Group::class, 'g')
            ->join(DailyNote::class, 'n', 'WITH', 'n.group = g')
            ->where('n.academicYear = :year')
            ->andWhere('n.type = :type')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('type', $type->getId(), 'uuid')
            ->orderBy('g.name', 'ASC');

        $hasFullAccess = $viewer->isAdmin() || $centre->getAdmins()->contains($viewer);
        if (!$hasFullAccess) {
            $qb->andWhere(':viewer MEMBER OF g.tutors')
               ->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        /** @var Group[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Una fila por (estudiante, grupo) con al menos una nota del tipo dado en el curso, con
     * recuentos de activas/inactivas y el rango de fechas de las activas — para la pestaña
     * "Listado de estudiantes". Mismo patrón estructural que
     * {@see StudentRepository::findTutoredSummary()}. Visibilidad: admin global o de centro ven
     * todos los grupos; el resto solo los que tutorizan (sin la rama de "registrada por mí", a
     * diferencia del resto de notas: aquí solo interesan filas sobre las que se puede actuar).
     *
     * @param array<string, mixed> $filters
     * @return list<array{studentId: string, firstName: string, lastName: string, groupId: string, groupName: string, activeCount: int, inactiveCount: int, activeFrom: ?\DateTimeImmutable, activeTo: ?\DateTimeImmutable}>
     */
    public function findStudentSummaryByType(EducationalCentre $centre, Teacher $viewer, AcademicYear $year, DailyNoteType $type, array $filters = []): array
    {
        $dql = '
            SELECT
                s.id AS studentId,
                s.name.firstName AS firstName,
                s.name.lastName AS lastName,
                g.id AS groupId,
                g.name AS groupName,
                (SELECT COUNT(n1.id) FROM App\Entity\DailyNote n1
                 WHERE n1.student = s AND n1.group = g AND n1.type = :type AND n1.academicYear = :year AND n1.active = true) AS activeCount,
                (SELECT COUNT(n2.id) FROM App\Entity\DailyNote n2
                 WHERE n2.student = s AND n2.group = g AND n2.type = :type AND n2.academicYear = :year AND n2.active = false) AS inactiveCount,
                (SELECT MIN(n3.occurredAt) FROM App\Entity\DailyNote n3
                 WHERE n3.student = s AND n3.group = g AND n3.type = :type AND n3.academicYear = :year AND n3.active = true) AS activeFrom,
                (SELECT MAX(n4.occurredAt) FROM App\Entity\DailyNote n4
                 WHERE n4.student = s AND n4.group = g AND n4.type = :type AND n4.academicYear = :year AND n4.active = true) AS activeTo
            FROM App\Entity\Student s
            JOIN s.groups g
            JOIN g.course c
            JOIN c.academicYear ay
            WHERE ay = :year
            AND EXISTS (
                SELECT 1 FROM App\Entity\DailyNote n0
                WHERE n0.student = s AND n0.group = g AND n0.type = :type AND n0.academicYear = :year
            )
        ';

        $hasFullAccess = $viewer->isAdmin() || $centre->getAdmins()->contains($viewer);
        if (!$hasFullAccess) {
            $dql .= ' AND :viewer MEMBER OF g.tutors';
        }

        $groupId = $filters['groupId'] ?? '';
        if (is_string($groupId) && $groupId !== '') {
            $dql .= ' AND g.id = :groupId';
        }

        $search = $filters['search'] ?? '';
        if (is_string($search) && $search !== '') {
            $dql .= ' AND (LOWER(s.name.firstName) LIKE LOWER(:search)
                           OR LOWER(s.name.lastName) LIKE LOWER(:search)
                           OR LOWER(g.name) LIKE LOWER(:search))';
        }

        $query = $this->getEntityManager()
            ->createQuery($dql)
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('type', $type->getId(), 'uuid');

        if (!$hasFullAccess) {
            $query->setParameter('viewer', $viewer->getId(), 'uuid');
        }
        if (is_string($groupId) && $groupId !== '') {
            $query->setParameter('groupId', $groupId, 'uuid');
        }
        if (is_string($search) && $search !== '') {
            $query->setParameter('search', '%' . $search . '%');
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = $query->getArrayResult();

        /** @var list<array{studentId: string, firstName: string, lastName: string, groupId: string, groupName: string, activeCount: int, inactiveCount: int, activeFrom: ?\DateTimeImmutable, activeTo: ?\DateTimeImmutable}> $rows */
        $rows = array_map(
            static function (array $row): array {
                $studentId = $row['studentId'];
                $groupId   = $row['groupId'];

                return [
                    'studentId'     => $studentId instanceof Uuid ? $studentId->toRfc4122() : '',
                    'firstName'     => is_string($row['firstName']) ? $row['firstName'] : '',
                    'lastName'      => is_string($row['lastName']) ? $row['lastName'] : '',
                    'groupId'       => $groupId instanceof Uuid ? $groupId->toRfc4122() : '',
                    'groupName'     => is_string($row['groupName']) ? $row['groupName'] : '',
                    'activeCount'   => is_scalar($row['activeCount']) ? (int) $row['activeCount'] : 0,
                    'inactiveCount' => is_scalar($row['inactiveCount']) ? (int) $row['inactiveCount'] : 0,
                    'activeFrom'    => self::toDateTimeImmutable($row['activeFrom']),
                    'activeTo'      => self::toDateTimeImmutable($row['activeTo']),
                ];
            },
            $raw,
        );

        usort(
            $rows,
            static fn (array $a, array $b): int =>
                ($b['activeCount'] <=> $a['activeCount'])
                ?: strcmp($a['groupName'], $b['groupName'])
                ?: strcmp($a['lastName'], $b['lastName'])
                ?: strcmp($a['firstName'], $b['firstName']),
        );

        return $rows;
    }

    /**
     * Desactiva en bloque todas las notas activas de cualquier tipo de un estudiante en el curso
     * dado (se usa al registrar un parte desde la pestaña "Listado de estudiantes"). Devuelve el
     * número de notas desactivadas.
     */
    public function deactivateAllActiveForStudent(Student $student, AcademicYear $year): int
    {
        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.active', ':false')
            ->where('n.student = :student')
            ->andWhere('n.academicYear = :year')
            ->andWhere('n.active = :true')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('false', false)
            ->setParameter('true', true)
            ->getQuery()
            ->execute();
    }

    /**
     * Filas (estudiante, grupo, tipo) donde el recuento de notas activas iguala o supera el
     * umbral de ese tipo — para la campana de notificaciones y el panel de inicio de
     * tutores/administración. Misma visibilidad estrecha que {@see findStudentSummaryByType()}
     * (admin ve todo el centro; el resto solo sus grupos tutorizados). Como no se puede filtrar
     * "activeCount >= threshold" en el propio DQL (alias de subconsulta), se trae el producto
     * completo (estudiantes del curso × tipos con umbral) y se filtra en PHP — acotado porque el
     * número de tipos por centro es pequeño.
     *
     * @return list<array{studentId: string, firstName: string, lastName: string, groupId: string, groupName: string, typeId: string, typeName: string, activeCount: int, threshold: int, lastNoteAt: ?\DateTimeImmutable}>
     */
    public function findStudentsAtThreshold(EducationalCentre $centre, Teacher $viewer, AcademicYear $year): array
    {
        $dql = '
            SELECT
                s.id AS studentId,
                s.name.firstName AS firstName,
                s.name.lastName AS lastName,
                g.id AS groupId,
                g.name AS groupName,
                t.id AS typeId,
                t.name AS typeName,
                t.occurrencesForReport AS threshold,
                (SELECT COUNT(n1.id) FROM App\Entity\DailyNote n1
                 WHERE n1.student = s AND n1.group = g AND n1.type = t AND n1.academicYear = :year AND n1.active = true) AS activeCount,
                (SELECT MAX(n2.occurredAt) FROM App\Entity\DailyNote n2
                 WHERE n2.student = s AND n2.group = g AND n2.type = t AND n2.academicYear = :year AND n2.active = true) AS lastNoteAt
            FROM App\Entity\DailyNoteType t, App\Entity\Student s
            JOIN s.groups g
            JOIN g.course c
            JOIN c.academicYear ay
            WHERE ay = :year
            AND t.educationalCentre = :centre
            AND t.occurrencesForReport > 0
        ';

        $hasFullAccess = $viewer->isAdmin() || $centre->getAdmins()->contains($viewer);
        if (!$hasFullAccess) {
            $dql .= ' AND :viewer MEMBER OF g.tutors';
        }

        $query = $this->getEntityManager()
            ->createQuery($dql)
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('centre', $centre->getId(), 'uuid');

        if (!$hasFullAccess) {
            $query->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        /** @var list<array<string, mixed>> $raw */
        $raw = $query->getArrayResult();

        /** @var list<array{studentId: string, firstName: string, lastName: string, groupId: string, groupName: string, typeId: string, typeName: string, activeCount: int, threshold: int, lastNoteAt: ?\DateTimeImmutable}> $rows */
        $rows = [];
        foreach ($raw as $row) {
            $activeCount = is_scalar($row['activeCount']) ? (int) $row['activeCount'] : 0;
            $threshold   = is_scalar($row['threshold']) ? (int) $row['threshold'] : 0;
            if ($activeCount < $threshold) {
                continue;
            }

            $studentId = $row['studentId'];
            $groupId   = $row['groupId'];
            $typeId    = $row['typeId'];

            $rows[] = [
                'studentId'  => $studentId instanceof Uuid ? $studentId->toRfc4122() : '',
                'firstName'  => is_string($row['firstName']) ? $row['firstName'] : '',
                'lastName'   => is_string($row['lastName']) ? $row['lastName'] : '',
                'groupId'    => $groupId instanceof Uuid ? $groupId->toRfc4122() : '',
                'groupName'  => is_string($row['groupName']) ? $row['groupName'] : '',
                'typeId'     => $typeId instanceof Uuid ? $typeId->toRfc4122() : '',
                'typeName'   => is_string($row['typeName']) ? $row['typeName'] : '',
                'activeCount' => $activeCount,
                'threshold'  => $threshold,
                'lastNoteAt' => self::toDateTimeImmutable($row['lastNoteAt']),
            ];
        }

        return $rows;
    }

    public function findById(string $id): ?DailyNote
    {
        $result = $this->createQueryBuilder('n')
            ->where('n.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof DailyNote ? $result : null;
    }

    /**
     * Notas activas de un estudiante de un tipo concreto, de más reciente a más antigua — para
     * componer el correo de "umbral alcanzado" con el detalle de cada una.
     *
     * @return list<DailyNote>
     */
    public function findActiveByStudentAndType(Student $student, DailyNoteType $type, AcademicYear $year): array
    {
        /** @var list<DailyNote> $result */
        $result = $this->createQueryBuilder('n')
            ->addSelect('t')
            ->join('n.registeredBy', 't')
            ->where('n.student = :student')
            ->andWhere('n.type = :type')
            ->andWhere('n.academicYear = :year')
            ->andWhere('n.active = true')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('type', $type->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('n.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Desactiva en bloque las notas activas de un tipo cuya fecha de ocurrencia es anterior o
     * igual al corte dado (caducidad automática, sin restricción de centro: se llama una vez por
     * tipo desde el manejador programado). Devuelve el número de notas desactivadas.
     */
    public function deactivateExpiredByType(DailyNoteType $type, \DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.active', ':false')
            ->where('n.type = :type')
            ->andWhere('n.active = :true')
            ->andWhere('n.occurredAt <= :cutoff')
            ->setParameter('type', $type->getId(), 'uuid')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('false', false)
            ->setParameter('true', true)
            ->getQuery()
            ->execute();
    }

    /**
     * getArrayResult() no aplica la conversión de tipo datetime_immutable a las columnas
     * calculadas por subconsulta (MIN/MAX): llegan como string sin tipar, a diferencia de las
     * columnas que mapean directamente un campo de la entidad raíz.
     */
    private static function toDateTimeImmutable(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
