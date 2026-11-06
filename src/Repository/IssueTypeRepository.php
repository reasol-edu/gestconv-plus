<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EducationalCentre;
use App\Entity\IssueType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<IssueType> */
class IssueTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IssueType::class);
    }

    /**
     * Todos los tipos del centro, con su padre y sus responsables ya cargados (el árbol se arma en memoria).
     *
     * @return list<IssueType>
     */
    public function findByCentre(EducationalCentre $centre): array
    {
        /** @var list<IssueType> $result */
        $result = $this->createQueryBuilder('t')
            ->addSelect('p', 'm')
            ->leftJoin('t.parent', 'p')
            ->leftJoin('t.managers', 'm')
            ->where('t.educationalCentre = :centre')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function findByCentreAndId(EducationalCentre $centre, string $id): ?IssueType
    {
        if (!\Symfony\Component\Uid\Uuid::isValid($id)) {
            return null;
        }

        /** @var IssueType|null $result */
        $result = $this->createQueryBuilder('t')
            ->where('t.educationalCentre = :centre')
            ->andWhere('t.id = :id')
            ->setParameter('centre', $centre->getId(), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    public function countChildren(IssueType $type): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.parent = :type')
            ->setParameter('type', $type->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
