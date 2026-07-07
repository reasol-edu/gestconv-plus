<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\Group;
use App\Entity\IncidentReport;
use App\Entity\Student;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IncidentReport>
 */
class IncidentReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentReport::class);
    }

    /**
     * Builds a pageable/filterable query for incident reports visible to the viewer.
     *
     * Visibility rules:
     *  - Global admin              → all reports of the centre
     *  - Centre admin              → all reports of the centre
     *  - Committee member / counselor → all reports of the centre
     *  - Other teacher             → only reports they registered OR reports for groups they tutor
     *
     * Accepted filter keys (all optional):
     *   search   string   — fulltext search
     *   ownOnly  bool     — only viewer's own reports
     *   groupId  string   — UUID of a group
     *   studentId string  — UUID of a student
     *   dateFrom string   — Y-m-d lower bound
     *   dateTo   string   — Y-m-d upper bound
     *   serious  bool     — filter by behavior severity
     *   expelled bool     — filter by expelled flag
     *   sort     string   — column key
     *   sortDir  string   — 'asc'|'desc'
     *
     * Pass $year to restrict results to a single academic year (each academic year is meant to
     * be sealed off from the others); omit it only for genuinely cross-year views, such as a
     * student's full disciplinary history in {@see \App\Controller\StudentController}.
     *
     * @param array<string, mixed> $filters
     * @return Query<null, IncidentReport>
     */
    public function createFilteredQuery(
        EducationalCentre $centre,
        Teacher $viewer,
        array $filters = [],
        ?AcademicYear $year = null,
    ): Query {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('g', 's', 't', 'beh', 'bc')
            ->join('r.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->join('r.student', 's')
            ->join('r.registeredBy', 't')
            ->leftJoin('r.behaviors', 'beh')
            ->leftJoin('beh.category', 'bc')
            ->where('ay.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid');

        if ($year !== null) {
            $qb->andWhere('ay = :year')->setParameter('year', $year->getId(), 'uuid');
        }

        // Visibility restriction for non-admins
        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'r.registeredBy = :viewer',
                    ':viewer MEMBER OF g.tutors',
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        // ownOnly
        $ownOnly = $filters['ownOnly'] ?? false;
        if ($ownOnly === true) {
            $qb->andWhere('r.registeredBy = :viewerOwn')
               ->setParameter('viewerOwn', $viewer->getId(), 'uuid');
        }

        // groupId
        $groupId = $filters['groupId'] ?? '';
        if (is_string($groupId) && $groupId !== '') {
            $qb->andWhere('g.id = :groupId')
               ->setParameter('groupId', $groupId, 'uuid');
        }

        // studentId
        $studentId = $filters['studentId'] ?? '';
        if (is_string($studentId) && $studentId !== '') {
            $qb->andWhere('r.student = :studentId')
               ->setParameter('studentId', $studentId, 'uuid');
        }

        // dateFrom
        $dateFrom = $filters['dateFrom'] ?? '';
        if (is_string($dateFrom) && $dateFrom !== '') {
            try {
                $from = new \DateTimeImmutable($dateFrom);
                $qb->andWhere('r.occurredAt >= :dateFrom')
                   ->setParameter('dateFrom', $from->setTime(0, 0, 0));
            } catch (\Exception) {
                // ignore invalid date
            }
        }

        // dateTo
        $dateTo = $filters['dateTo'] ?? '';
        if (is_string($dateTo) && $dateTo !== '') {
            try {
                $to = new \DateTimeImmutable($dateTo);
                $qb->andWhere('r.occurredAt <= :dateTo')
                   ->setParameter('dateTo', $to->setTime(23, 59, 59));
            } catch (\Exception) {
                // ignore invalid date
            }
        }

        // serious
        $serious = $filters['serious'] ?? null;
        if (is_bool($serious)) {
            $qb->andWhere('bc.serious = :serious')
               ->setParameter('serious', $serious);
        }

        // expelled
        $expelled = $filters['expelled'] ?? null;
        if (is_bool($expelled)) {
            $qb->andWhere('r.expelledFromClass = :expelled')
               ->setParameter('expelled', $expelled);
        }

        // search
        $search = $filters['search'] ?? '';
        if (is_string($search) && $search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'LOWER(s.name.firstName) LIKE LOWER(:search)',
                    'LOWER(s.name.lastName) LIKE LOWER(:search)',
                    'LOWER(t.name.firstName) LIKE LOWER(:search)',
                    'LOWER(t.name.lastName) LIKE LOWER(:search)',
                    'LOWER(g.name) LIKE LOWER(:search)',
                    'LOWER(beh.name) LIKE LOWER(:search)',
                    'LOWER(r.description) LIKE LOWER(:search)',
                )
            )->setParameter('search', '%' . $search . '%');
        }

        // sorting
        $sortDirRaw = $filters['sortDir'] ?? 'desc';
        $sortDir    = is_string($sortDirRaw) && strtolower($sortDirRaw) === 'asc' ? 'ASC' : 'DESC';

        $sortRaw = $filters['sort'] ?? '';
        $sort    = is_string($sortRaw) ? $sortRaw : '';

        match ($sort) {
            'student' => $qb->orderBy('s.name.lastName', $sortDir)->addOrderBy('s.name.firstName', $sortDir),
            'teacher' => $qb->orderBy('t.name.lastName', $sortDir)->addOrderBy('t.name.firstName', $sortDir),
            'group'   => $qb->orderBy('g.name', $sortDir),
            default   => $qb->orderBy('r.occurredAt', 'DESC'),
        };

        return $qb->distinct()->getQuery();
    }

    /**
     * Count reports in the last $days days that the viewer can see, restricted to $year.
     */
    public function countRecentByCentre(EducationalCentre $centre, Teacher $viewer, int $days = 30, ?AcademicYear $year = null): int
    {
        $since = new \DateTimeImmutable('-' . $days . ' days');

        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.id)')
            ->join('r.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('r.occurredAt >= :since')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('since', $since);

        if ($year !== null) {
            $qb->andWhere('ay = :year')->setParameter('year', $year->getId(), 'uuid');
        }

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'r.registeredBy = :viewer',
                    ':viewer MEMBER OF g.tutors',
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns the distinct groups that have at least one incident report visible to the viewer,
     * ordered by group name. Used to populate the group filter dropdown.
     *
     * @return Group[]
     */
    public function findGroupsWithReports(EducationalCentre $centre, Teacher $viewer, ?AcademicYear $year = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('g')
            ->distinct()
            ->from(Group::class, 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->join(IncidentReport::class, 'r', 'WITH', 'r.group = g')
            ->where('ay.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('g.name', 'ASC');

        if ($year !== null) {
            $qb->andWhere('ay = :year')->setParameter('year', $year->getId(), 'uuid');
        }

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'r.registeredBy = :viewer',
                    ':viewer MEMBER OF g.tutors',
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        /** @var Group[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Searches student+group pairs for the incident report form autocomplete.
     *
     * @return list<array{student: Student, group: Group}>
     */
    public function searchStudentGroupPairs(
        EducationalCentre $centre,
        string $q,
        int $limit = 20,
    ): array {
        $year = $centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        /** @var list<Student> $students */
        $students = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('s', 'g')
            ->from(Student::class, 's')
            ->join('s.groups', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay = :year')
            ->andWhere(
                'LOWER(s.name.firstName) LIKE LOWER(:search)
                 OR LOWER(s.name.lastName) LIKE LOWER(:search)
                 OR LOWER(s.studentId) LIKE LOWER(:search)
                 OR LOWER(g.name) LIKE LOWER(:search)'
            )
            ->setParameter('year', $year->getId(), 'uuid')
            ->setParameter('search', '%' . $q . '%')
            ->orderBy('s.name.lastName', 'ASC')
            ->addOrderBy('s.name.firstName', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var list<array{student: Student, group: Group}> $pairs */
        $pairs = [];

        foreach ($students as $student) {
            foreach ($student->getGroups() as $group) {
                // Only include groups from the active year
                if ($group->getProgrammeYear()->getProgramme()->getAcademicYear() === $year) {
                    $pairs[] = ['student' => $student, 'group' => $group];
                }
            }
        }

        return array_slice($pairs, 0, $limit);
    }

    public function nextNumberForYear(AcademicYear $year): int
    {
        $max = $this->createQueryBuilder('r')
            ->select('MAX(r.number)')
            ->where('r.academicYear = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($max ?? 0) + 1;
    }

    /**
     * Returns reports pending notification (no successful communication yet) visible to the viewer,
     * ordered by occurrence date ascending (oldest first).
     *
     * @return list<IncidentReport>
     */
    public function findPendingNotification(EducationalCentre $centre, Teacher $viewer, ?AcademicYear $year = null): array
    {
        /** @var list<IncidentReport> $result */
        $result = $this->buildPendingQueryBuilder($centre, $viewer, $year)->getQuery()->getResult();

        return $result;
    }

    /**
     * Pageable version of {@see findPendingNotification()}, for the paginated "Notificaciones
     * pendientes" list.
     *
     * @return Query<null, IncidentReport>
     */
    public function createPendingQuery(EducationalCentre $centre, Teacher $viewer, ?AcademicYear $year = null): Query
    {
        return $this->buildPendingQueryBuilder($centre, $viewer, $year)->getQuery();
    }

    private function buildPendingQueryBuilder(EducationalCentre $centre, Teacher $viewer, ?AcademicYear $year = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('s', 'g')
            ->join('r.student', 's')
            ->join('r.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('r.notifiedCommunication IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('r.occurredAt', 'ASC');

        if ($year !== null) {
            $qb->andWhere('ay = :year')->setParameter('year', $year->getId(), 'uuid');
        }

        $hasFullAccess = $centre->getAdmins()->contains($viewer)
            || $centre->getCommitteeMembers()->contains($viewer)
            || $centre->getCounselors()->contains($viewer);
        if (!$viewer->isAdmin() && !$hasFullAccess) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'r.registeredBy = :viewer',
                    ':viewer MEMBER OF g.tutors',
                )
            )->setParameter('viewer', $viewer->getId(), 'uuid');
        }

        return $qb;
    }

    /**
     * Builds a pageable query for non-notified reports the viewer is actually authorised to
     * notify, mirroring IncidentReportVoter::NOTIFY (stricter than the plain view-based
     * visibility of {@see findPendingNotification()}): committee members and counselors are
     * NOT granted notify rights just by their role, only admins, the report's registrant or
     * the group's tutor(s), depending on the centre's "notifications.report_notifier" setting.
     *
     * @param 'report_teacher'|'group_tutor'|'both'|string $notifierSetting
     * @return Query<null, IncidentReport>
     */
    public function createNotifiableQuery(
        EducationalCentre $centre,
        Teacher $viewer,
        string $notifierSetting,
        ?Student $student = null,
        ?AcademicYear $year = null,
    ): Query {
        $qb = $this->buildNotifiableQueryBuilder($centre, $viewer, $notifierSetting, $year)
            ->orderBy('r.occurredAt', 'ASC');

        if ($student !== null) {
            $qb->andWhere('r.student = :student')
               ->setParameter('student', $student->getId(), 'uuid');
        }

        return $qb->getQuery();
    }

    /**
     * Students with at least one pending report the viewer is authorised to notify, with their
     * pending count, ordered by count descending then student name ascending.
     *
     * Rooted at Student (rather than reusing {@see buildNotifiableQueryBuilder()} directly)
     * because Doctrine DQL forbids selecting a joined entity as a full object without also
     * selecting the root entity alias.
     *
     * @param 'report_teacher'|'group_tutor'|'both'|string $notifierSetting
     * @return list<array{student: Student, count: int}>
     */
    public function findNotifiableSummaryByStudent(
        EducationalCentre $centre,
        Teacher $viewer,
        string $notifierSetting,
        ?AcademicYear $year = null,
    ): array {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('st', 'COUNT(r.id) AS reportCount')
            ->from(Student::class, 'st')
            ->join(IncidentReport::class, 'r', 'WITH', 'r.student = st')
            ->join('r.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('r.notifiedCommunication IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->groupBy('st.id')
            ->orderBy('COUNT(r.id)', 'DESC')
            ->addOrderBy('st.name.lastName', 'ASC')
            ->addOrderBy('st.name.firstName', 'ASC');

        if ($year !== null) {
            $qb->andWhere('ay = :year')->setParameter('year', $year->getId(), 'uuid');
        }

        $this->applyNotifiableRestriction($qb, $centre, $viewer, $notifierSetting);

        /** @var list<array{0: Student, reportCount: int|string}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $row): array => ['student' => $row[0], 'count' => (int) $row['reportCount']],
            $rows,
        );
    }

    /**
     * @param 'report_teacher'|'group_tutor'|'both'|string $notifierSetting
     */
    private function buildNotifiableQueryBuilder(
        EducationalCentre $centre,
        Teacher $viewer,
        string $notifierSetting,
        ?AcademicYear $year = null,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('r')
            ->join('r.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('r.notifiedCommunication IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid');

        if ($year !== null) {
            $qb->andWhere('ay = :year')->setParameter('year', $year->getId(), 'uuid');
        }

        $this->applyNotifiableRestriction($qb, $centre, $viewer, $notifierSetting);

        return $qb;
    }

    /**
     * Applies the IncidentReportVoter::NOTIFY restriction to a query builder that already has
     * 'r' (IncidentReport) and 'g' (Group) aliases joined, regardless of which entity is the
     * DQL root.
     *
     * @param 'report_teacher'|'group_tutor'|'both'|string $notifierSetting
     */
    private function applyNotifiableRestriction(
        QueryBuilder $qb,
        EducationalCentre $centre,
        Teacher $viewer,
        string $notifierSetting,
    ): void {
        if ($viewer->isAdmin() || $centre->getAdmins()->contains($viewer)) {
            return;
        }

        match ($notifierSetting) {
            'report_teacher' => $qb->andWhere('r.registeredBy = :viewer')
                ->setParameter('viewer', $viewer->getId(), 'uuid'),
            'group_tutor' => $qb->andWhere(':viewer MEMBER OF g.tutors')
                ->setParameter('viewer', $viewer->getId(), 'uuid'),
            default => $qb->andWhere(
                $qb->expr()->orX('r.registeredBy = :viewer', ':viewer MEMBER OF g.tutors')
            )->setParameter('viewer', $viewer->getId(), 'uuid'),
        };
    }

    public function findById(string $id): ?IncidentReport
    {
        $result = $this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof IncidentReport ? $result : null;
    }

    /**
     * Reports of the given centre that are still un-notified, not already prescribed, and whose
     * incident occurred at or before the cutoff — candidates for automatic prescription.
     *
     * @return list<IncidentReport>
     */
    public function findEligibleForAutoPrescription(EducationalCentre $centre, \DateTimeImmutable $cutoff): array
    {
        /** @var list<IncidentReport> $result */
        $result = $this->createQueryBuilder('r')
            ->join('r.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('r.notifiedCommunication IS NULL')
            ->andWhere('r.prescribedAt IS NULL')
            ->andWhere('r.occurredAt <= :cutoff')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * All reports of the centre still un-notified and not prescribed, regardless of how close they
     * are to the centre's auto-prescription cutoff — used to compute upcoming-prescription warnings,
     * where the remaining days depend on each recipient's personal warning threshold.
     *
     * @return list<IncidentReport>
     */
    public function findPendingPrescription(EducationalCentre $centre): array
    {
        /** @var list<IncidentReport> $result */
        $result = $this->createQueryBuilder('r')
            ->addSelect('g')
            ->join('r.group', 'g')
            ->join('g.programmeYear', 'py')
            ->join('py.programme', 'prog')
            ->join('prog.academicYear', 'ay')
            ->where('ay.educationalCentre = :centre')
            ->andWhere('r.notifiedCommunication IS NULL')
            ->andWhere('r.prescribedAt IS NULL')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
