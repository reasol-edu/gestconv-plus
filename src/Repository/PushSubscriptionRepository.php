<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\Teacher;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    public function findByEndpoint(string $endpoint): ?PushSubscription
    {
        $result = $this->createQueryBuilder('p')
            ->where('p.endpointHash = :hash')
            ->setParameter('hash', PushSubscription::hashEndpoint($endpoint))
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof PushSubscription ? $result : null;
    }

    /** @return list<PushSubscription> */
    public function findByTeacher(Teacher $teacher): array
    {
        /** @var list<PushSubscription> $result */
        $result = $this->createQueryBuilder('p')
            ->where('p.teacher = :teacher')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Las suscripciones de varios docentes en una sola consulta, agrupadas por docente (RFC 4122).
     *
     * @param  list<Teacher> $teachers
     * @return array<string, list<PushSubscription>>
     */
    public function findByTeachers(array $teachers): array
    {
        if ($teachers === []) {
            return [];
        }

        $qb           = $this->createQueryBuilder('p')->addSelect('t')->join('p.teacher', 't');
        $placeholders = [];
        foreach ($teachers as $i => $teacher) {
            $placeholders[] = ":t{$i}";
            $qb->setParameter("t{$i}", $teacher->getId(), 'uuid');
        }

        /** @var list<PushSubscription> $found */
        $found = $qb->where('p.teacher IN (' . implode(', ', $placeholders) . ')')->getQuery()->getResult();

        $byTeacher = [];
        foreach ($found as $subscription) {
            $byTeacher[$subscription->getTeacher()->getId()->toRfc4122()][] = $subscription;
        }

        return $byTeacher;
    }

    public function countByTeacher(Teacher $teacher): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.teacher = :teacher')
            ->setParameter('teacher', $teacher->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
