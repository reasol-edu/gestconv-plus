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
        bool $excludeIgnored = true,
    ): int {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.student = :student')
            ->andWhere('n.type = :type')
            ->andWhere('n.academicYear = :year')
            ->setParameter('student', $student->getId(), 'uuid')
            ->setParameter('type', $type->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid');

        if ($excludeIgnored) {
            $qb->andWhere('n.ignored = false');
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

    public function findById(string $id): ?DailyNote
    {
        $result = $this->createQueryBuilder('n')
            ->where('n.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof DailyNote ? $result : null;
    }
}
