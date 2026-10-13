<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Sanction;
use App\Entity\SanctionTaskAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SanctionTaskAttachment>
 */
class SanctionTaskAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SanctionTaskAttachment::class);
    }

    public function findById(string $id): ?SanctionTaskAttachment
    {
        $result = $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof SanctionTaskAttachment ? $result : null;
    }

    /**
     * Adjuntos de todas las tareas de una sanción en una sola consulta, agrupados por id de tarea
     * (RFC4122) y ordenados por nombre de fichero.
     *
     * @return array<string, list<SanctionTaskAttachment>>
     */
    public function findBySanctionGroupedByTask(Sanction $sanction): array
    {
        /** @var list<SanctionTaskAttachment> $attachments */
        $attachments = $this->createQueryBuilder('a')
            ->addSelect('t')
            ->join('a.task', 't')
            ->where('t.sanction = :sanction')
            ->setParameter('sanction', $sanction->getId(), 'uuid')
            ->orderBy('a.filename', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($attachments as $attachment) {
            $grouped[$attachment->getTask()->getId()->toRfc4122()][] = $attachment;
        }

        return $grouped;
    }
}
