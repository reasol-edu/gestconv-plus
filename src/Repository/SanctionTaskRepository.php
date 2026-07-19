<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\GroupTeacher;
use App\Entity\Sanction;
use App\Entity\SanctionTask;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SanctionTask>
 */
class SanctionTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SanctionTask::class);
    }

    public function findById(string $id): ?SanctionTask
    {
        $result = $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SanctionTask ? $result : null;
    }

    /**
     * Whether any sanction task still references this group/teacher/subject assignment. Used to
     * block removing the assignment from the group's teaching offer directly (that would silently
     * destroy the task's content); such removals must go through "refrescar materias" instead,
     * which warns about the tasks it would delete.
     */
    public function existsForGroupTeacher(GroupTeacher $groupTeacher): bool
    {
        $count = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.groupTeacher = :groupTeacher')
            ->setParameter('groupTeacher', $groupTeacher->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * Returns every task of a sanction, ordered by subject and teacher name, for the tracking
     * block shown to tutor/committee/admins and the read-only sibling list shown to teachers.
     *
     * @return list<SanctionTask>
     */
    public function findBySanction(Sanction $sanction): array
    {
        /** @var list<SanctionTask> $result */
        $result = $this->createQueryBuilder('t')
            ->addSelect('gt', 'te')
            ->join('t.groupTeacher', 'gt')
            ->join('gt.teacher', 'te')
            ->where('t.sanction = :sanction')
            ->setParameter('sanction', $sanction->getId(), 'uuid')
            ->orderBy('gt.subject', 'ASC')
            ->addOrderBy('te.name.lastName', 'ASC')
            ->addOrderBy('te.name.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Returns the teacher's own tasks (own subjects) for the given centre/year, pending tasks
     * first, then completed/not-applicable ones.
     *
     * @return list<SanctionTask>
     */
    public function findForTeacher(EducationalCentre $centre, Teacher $teacher, AcademicYear $year): array
    {
        /** @var list<SanctionTask> $result */
        $result = $this->createQueryBuilder('t')
            ->addSelect('s', 'gt', 'st')
            ->join('t.sanction', 's')
            ->join('s.student', 'st')
            ->join('t.groupTeacher', 'gt')
            ->where('s.academicYear = :year')
            ->andWhere('gt.teacher = :teacher')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        usort(
            $result,
            static fn (SanctionTask $a, SanctionTask $b): int =>
                ($a->getCompletedAt() === null ? 0 : 1) <=> ($b->getCompletedAt() === null ? 0 : 1),
        );

        return $result;
    }

    /**
     * Counts the teacher's own pending tasks for the given centre/year (dashboard widget).
     */
    public function countPendingForTeacher(EducationalCentre $centre, Teacher $teacher, AcademicYear $year): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.sanction', 's')
            ->join('t.groupTeacher', 'gt')
            ->where('s.academicYear = :year')
            ->andWhere('gt.teacher = :teacher')
            ->andWhere('t.completedAt IS NULL')
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Counts sanctions of the given centre/year, visible to the viewer, that have at least one
     * incomplete task (dashboard widget for tutor/committee/admins).
     */
    public function countSanctionsWithIncompleteTasks(EducationalCentre $centre, Teacher $viewer, AcademicYear $year): int
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(DISTINCT s.id)')
            ->from(Sanction::class, 's')
            ->join('s.group', 'g')
            ->join('s.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('ay = :year')
            ->andWhere('EXISTS (SELECT 1 FROM ' . SanctionTask::class . ' xt WHERE xt.sanction = s AND xt.completedAt IS NULL)')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('year', $year->getId(), 'uuid');

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->join('s.reports', 'r')
               ->andWhere(
                   $qb->expr()->orX(
                       'r.registeredBy = :viewer',
                       ':viewer MEMBER OF g.tutors',
                   )
               )
               ->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns tasks with attachments belonging to sanctions of the given centre whose
     * effectiveTo is older than the cutoff, for the attachment purge handler.
     *
     * @return list<SanctionTask>
     */
    public function findWithAttachmentsOlderThan(EducationalCentre $centre, \DateTimeImmutable $cutoff): array
    {
        /** @var list<SanctionTask> $result */
        $result = $this->createQueryBuilder('t')
            ->join('t.sanction', 's')
            ->join('s.academicYear', 'ay')
            ->join('t.attachments', 'att')
            ->addSelect('att')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('s.effectiveTo < :cutoff')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Returns task completion counts (completed vs. total) for the given sanctions, keyed by
     * sanction UUID (RFC4122). Sanctions without any generated task are absent from the map.
     * Single query; avoids N+1 per row on the sanction listing.
     *
     * @param  Sanction[] $sanctions
     * @return array<string, array{completed: int, total: int}>
     */
    public function findCompletionCountsBySanctions(array $sanctions): array
    {
        if ($sanctions === []) {
            return [];
        }

        // Each UUID is bound individually with the explicit 'uuid' type: passing the whole
        // array as a single IN (:sanctions) parameter makes Doctrine infer a generic
        // ArrayParameterType (string) that skips the UuidType conversion (binary on
        // MySQL/SQLite, native on PostgreSQL), and the query then finds no rows at all.
        $qb           = $this->createQueryBuilder('t');
        $placeholders = [];
        foreach ($sanctions as $i => $sanction) {
            $placeholders[] = ":sanction{$i}";
            $qb->setParameter("sanction{$i}", $sanction->getId(), 'uuid');
        }

        /** @var list<array<string, int|string>> $rows */
        $rows = $qb
            ->select('IDENTITY(t.sanction) AS sid, COUNT(t.id) AS total, COUNT(t.completedAt) AS completed')
            ->where('IDENTITY(t.sanction) IN (' . implode(', ', $placeholders) . ')')
            ->groupBy('t.sanction')
            ->getQuery()
            ->getScalarResult();

        // getScalarResult() returns UUIDs in binary form on MySQL.
        $uuidNorm = [];
        foreach ($sanctions as $sanction) {
            $rfc = $sanction->getId()->toRfc4122();
            $uuidNorm[$rfc]                          = $rfc;
            $uuidNorm[$sanction->getId()->toBinary()] = $rfc;
        }
        $normalize = static fn (int|string $raw): string =>
            $uuidNorm[(string) $raw] ?? (string) $raw;

        $map = [];
        foreach ($rows as $row) {
            $map[$normalize($row['sid'])] = [
                'completed' => (int) $row['completed'],
                'total'     => (int) $row['total'],
            ];
        }

        return $map;
    }

    /**
     * Returns incomplete tasks of the given centre whose sanction starts within [from, to],
     * for the daily reminder handler.
     *
     * @return list<SanctionTask>
     */
    public function findIncompleteStartingWithin(EducationalCentre $centre, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<SanctionTask> $result */
        $result = $this->createQueryBuilder('t')
            ->addSelect('s', 'gt', 'st')
            ->join('t.sanction', 's')
            ->join('s.student', 'st')
            ->join('s.academicYear', 'ay')
            ->join('t.groupTeacher', 'gt')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('t.completedAt IS NULL')
            ->andWhere('s.effectiveFrom >= :from')
            ->andWhere('s.effectiveFrom <= :to')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
