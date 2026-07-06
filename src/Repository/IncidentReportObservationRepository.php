<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IncidentReport;
use App\Entity\IncidentReportObservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IncidentReportObservation>
 */
class IncidentReportObservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IncidentReportObservation::class);
    }

    /**
     * @return list<IncidentReportObservation>
     */
    public function findByIncidentReport(IncidentReport $report): array
    {
        /** @var list<IncidentReportObservation> $result */
        $result = $this->createQueryBuilder('o')
            ->where('o.incidentReport = :report')
            ->setParameter('report', $report->getId(), 'uuid')
            ->orderBy('o.registeredAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Observations for all given reports, grouped by report UUID (RFC4122). Single query; avoids N+1.
     *
     * @param  IncidentReport[] $reports
     * @return array<string, list<IncidentReportObservation>>
     */
    public function findByIncidentReports(array $reports): array
    {
        if ($reports === []) {
            return [];
        }

        // Cada UUID se vincula individualmente con el tipo 'uuid' explícito: pasar el
        // array completo como un único parámetro IN (:reports) hace que Doctrine infiera
        // un ArrayParameterType genérico (string) que ignora la conversión de UuidType
        // (binaria en MySQL/SQLite, nativa en PostgreSQL), y la consulta no encuentra
        // ninguna fila aunque los datos existan.
        $qb           = $this->createQueryBuilder('o');
        $placeholders = [];
        foreach ($reports as $i => $report) {
            $placeholders[] = ":report{$i}";
            $qb->setParameter("report{$i}", $report->getId(), 'uuid');
        }

        $rows = $qb
            ->where('IDENTITY(o.incidentReport) IN (' . implode(', ', $placeholders) . ')')
            ->orderBy('o.registeredAt', 'DESC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($reports as $report) {
            $map[$report->getId()->toRfc4122()] = [];
        }
        foreach ($rows as $row) {
            $map[$row->getIncidentReport()->getId()->toRfc4122()][] = $row;
        }

        return $map;
    }

    public function findById(string $id): ?IncidentReportObservation
    {
        $result = $this->createQueryBuilder('o')
            ->where('o.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof IncidentReportObservation ? $result : null;
    }
}
