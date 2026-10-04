<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Teacher>
 */
class TeacherRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Teacher::class);
    }

    public function findById(string $id): ?Teacher
    {
        $result = $this->createQueryBuilder('t')
            ->where('t.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Teacher ? $result : null;
    }

    /** @return Teacher[] */
    public function findByAcademicYearOrderedByName(AcademicYear $year): array
    {
        return $this->createByAcademicYearFilteredQuery($year)->getResult();
    }

    public function countByAcademicYear(AcademicYear $year): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.academicYears', 'ay')
            ->where('ay.id = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByAcademicYearAndId(AcademicYear $year, string $id): ?Teacher
    {
        $result = $this->createQueryBuilder('t')
            ->join('t.academicYears', 'ay')
            ->where('t.id = :id')
            ->andWhere('ay.id = :year')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('year', $year->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Teacher ? $result : null;
    }

/** @return Teacher[] */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.name.lastName', 'ASC')
            ->addOrderBy('t.name.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Query<null, Teacher> */
    public function createOrderedByNameQuery(): Query
    {
        return $this->createFilteredOrderedByNameQuery();
    }

    /** @return Query<null, Teacher> */
    public function createFilteredOrderedByNameQuery(string $search = ''): Query
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.name.lastName', 'ASC')
            ->addOrderBy('t.name.firstName', 'ASC');

        if ($search !== '') {
            $q = '%' . $search . '%';
            $qb->where(
                $qb->expr()->orX(
                    'UNACCENT(LOWER(t.name.firstName)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(t.name.lastName)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(t.username)) LIKE UNACCENT(LOWER(:q))',
                    'LOWER(t.email) LIKE LOWER(:q)',
                    'LOWER(t.pendingEmail) LIKE LOWER(:q)',
                )
            )->setParameter('q', $q);
        }

        return $qb->getQuery();
    }

    /** @return Query<null, Teacher> */
    public function createByAcademicYearFilteredQuery(AcademicYear $year, string $search = ''): Query
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.academicYears', 'ay')
            ->where('ay.id = :year')
            ->setParameter('year', $year->getId(), 'uuid')
            ->orderBy('t.name.lastName', 'ASC')
            ->addOrderBy('t.name.firstName', 'ASC');

        if ($search !== '') {
            $q = '%' . $search . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    'UNACCENT(LOWER(t.name.firstName)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(t.name.lastName)) LIKE UNACCENT(LOWER(:q))',
                    'UNACCENT(LOWER(t.username)) LIKE UNACCENT(LOWER(:q))',
                    'LOWER(t.email) LIKE LOWER(:q)',
                    'LOWER(t.pendingEmail) LIKE LOWER(:q)',
                )
            )->setParameter('q', $q);
        }

        return $qb->getQuery();
    }

    /**
     * Docentes con alguna vinculación en el curso: tutorizan o imparten en un grupo, tienen una
     * guardia asignada, tienen ausencias previstas, han registrado partes, sanciones o notas, o
     * tienen un rol en el centro (administración, comisión de convivencia u orientación; estos son
     * del centro, no del curso, pero impiden retirar al docente igualmente).
     *
     * Los ids se seleccionan como escalares (no se hidratan docentes) porque solo importa saber quién está vinculado.
     *
     * @return array<string, true> ids (RFC 4122) de los docentes vinculados
     */
    public function findConnectedIdsForYear(AcademicYear $year): array
    {
        $em     = $this->getEntityManager();
        $centre = $year->getEducationalCentre();

        $queries = [
            // Grupos del curso (tutoría y profesorado)
            ['SELECT t.id AS id FROM App\Entity\Group g JOIN g.course c JOIN g.tutors t WHERE c.academicYear = :year', 'year'],
            ['SELECT t.id AS id FROM App\Entity\GroupTeacher gt JOIN gt.group g JOIN g.course c JOIN gt.teacher t WHERE c.academicYear = :year', 'year'],
            // Guardias y ausencias del curso
            ['SELECT t.id AS id FROM App\Entity\TimeSlot ts JOIN ts.guards t WHERE ts.academicYear = :year', 'year'],
            ['SELECT t.id AS id FROM App\Entity\Absence a JOIN a.teacher t WHERE a.academicYear = :year', 'year'],
            // Lo que han registrado este curso
            ['SELECT t.id AS id FROM App\Entity\IncidentReport r JOIN r.registeredBy t WHERE r.academicYear = :year', 'year'],
            ['SELECT t.id AS id FROM App\Entity\Sanction s JOIN s.registeredBy t WHERE s.academicYear = :year', 'year'],
            ['SELECT t.id AS id FROM App\Entity\DailyNote n JOIN n.registeredBy t WHERE n.academicYear = :year', 'year'],
            // Roles del centro
            ['SELECT t.id AS id FROM App\Entity\EducationalCentre c JOIN c.admins t WHERE c = :centre', 'centre'],
            ['SELECT t.id AS id FROM App\Entity\EducationalCentre c JOIN c.committeeMembers t WHERE c = :centre', 'centre'],
            ['SELECT t.id AS id FROM App\Entity\EducationalCentre c JOIN c.counselors t WHERE c = :centre', 'centre'],
        ];

        $ids = [];
        foreach ($queries as [$dql, $param]) {
            $query = $em->createQuery($dql);
            $query->setParameter($param, $param === 'year' ? $year->getId() : $centre->getId(), 'uuid');
            /** @var list<array{id: mixed}> $rows */
            $rows = $query->getResult();
            foreach ($rows as $row) {
                if ($row['id'] instanceof Uuid) {
                    $ids[$row['id']->toRfc4122()] = true;
                }
            }
        }

        return $ids;
    }

    /** @return Query<null, Teacher> */
    public function findNoneQuery(): Query
    {
        return $this->createQueryBuilder('t')
            ->where('1 = 0')
            ->getQuery();
    }

    public function findByUsername(string $username): ?Teacher
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function findByEmailVerificationToken(string $token): ?Teacher
    {
        return $this->findOneBy(['emailVerificationToken' => Teacher::hashToken($token)]);
    }

    /**
     * ¿Está ya este correo asignado o pendiente de verificar por otro docente?
     * Coincidencia insensible a mayúsculas; se excluye al propio docente cuando
     * se pasa, para que el editor de perfil pueda «refrescar» su propio correo.
     */
    public function isEmailTakenByAnother(string $email, ?Teacher $exclude = null): bool
    {
        $qb = $this->createQueryBuilder('t')
            ->where('LOWER(t.email) = LOWER(:email) OR LOWER(t.pendingEmail) = LOWER(:email)')
            ->setParameter('email', $email)
            ->setMaxResults(1);

        if ($exclude !== null) {
            $qb->andWhere('t.id != :selfId')->setParameter('selfId', $exclude->getId(), 'uuid');
        }

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }

    public function findByPasswordResetToken(string $token): ?Teacher
    {
        return $this->findOneBy(['passwordResetToken' => Teacher::hashToken($token)]);
    }

    public function findByFullName(string $firstName, string $lastName): ?Teacher
    {
        $result = $this->createQueryBuilder('t')
            ->where('LOWER(t.name.firstName) = LOWER(:firstName)')
            ->andWhere('LOWER(t.name.lastName) = LOWER(:lastName)')
            ->setParameter('firstName', $firstName)
            ->setParameter('lastName', $lastName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof Teacher ? $result : null;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Teacher) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** @return Teacher[] */
    public function search(string $query, int $limit = 10): array
    {
        $q = '%' . $query . '%';

        return $this->createQueryBuilder('t')
            ->where('UNACCENT(LOWER(t.name.firstName)) LIKE UNACCENT(LOWER(:q))')
            ->orWhere('UNACCENT(LOWER(t.name.lastName)) LIKE UNACCENT(LOWER(:q))')
            ->orWhere('UNACCENT(LOWER(t.username)) LIKE UNACCENT(LOWER(:q))')
            ->setParameter('q', $q)
            ->orderBy('t.name.lastName', 'ASC')
            ->addOrderBy('t.name.firstName', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.active = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAdmins(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.admin = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Quick search by name / username for the global search palette.
     *
     * @return list<Teacher>
     */
    public function searchByAcademicYear(AcademicYear $year, string $q, int $limit = 5): array
    {
        /** @var list<Teacher> $result */
        $result = $this->createByAcademicYearFilteredQuery($year, $q)
            ->setMaxResults($limit)
            ->getResult();

        return $result;
    }
}
