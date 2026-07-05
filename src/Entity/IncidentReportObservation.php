<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IncidentReportObservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IncidentReportObservationRepository::class)]
class IncidentReportObservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'observations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IncidentReport $incidentReport;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Teacher $registeredBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $registeredAt;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    public function __construct(
        IncidentReport $incidentReport,
        Teacher $registeredBy,
        \DateTimeImmutable $registeredAt,
        string $text,
    ) {
        $this->incidentReport = $incidentReport;
        $this->registeredBy   = $registeredBy;
        $this->registeredAt   = $registeredAt;
        $this->text           = $text;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIncidentReport(): IncidentReport
    {
        return $this->incidentReport;
    }

    public function getRegisteredBy(): Teacher
    {
        return $this->registeredBy;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function getText(): string
    {
        return $this->text;
    }
}
