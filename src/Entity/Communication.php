<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CommunicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CommunicationRepository::class)]
class Communication
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private CommunicationMethod $method;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Teacher $performedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $performedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 20, enumType: CommunicationResult::class)]
    private CommunicationResult $result;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(inversedBy: 'communications')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?IncidentReport $incidentReport = null;

    #[ORM\ManyToOne(inversedBy: 'communications')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Sanction $sanction = null;

    private function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function forIncidentReport(
        IncidentReport $report,
        CommunicationMethod $method,
        Teacher $performedBy,
        \DateTimeImmutable $performedAt,
        CommunicationResult $result,
        ?string $description = null,
    ): self {
        $communication = new self();
        $communication->incidentReport = $report;
        $communication->method         = $method;
        $communication->performedBy    = $performedBy;
        $communication->performedAt    = $performedAt;
        $communication->result         = $result;
        $communication->description    = $description;

        return $communication;
    }

    public static function forSanction(
        Sanction $sanction,
        CommunicationMethod $method,
        Teacher $performedBy,
        \DateTimeImmutable $performedAt,
        CommunicationResult $result,
        ?string $description = null,
    ): self {
        $communication = new self();
        $communication->sanction    = $sanction;
        $communication->method      = $method;
        $communication->performedBy = $performedBy;
        $communication->performedAt = $performedAt;
        $communication->result      = $result;
        $communication->description = $description;

        return $communication;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMethod(): CommunicationMethod
    {
        return $this->method;
    }

    public function getPerformedBy(): Teacher
    {
        return $this->performedBy;
    }

    public function getPerformedAt(): \DateTimeImmutable
    {
        return $this->performedAt;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getResult(): CommunicationResult
    {
        return $this->result;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getIncidentReport(): ?IncidentReport
    {
        return $this->incidentReport;
    }

    public function getSanction(): ?Sanction
    {
        return $this->sanction;
    }
}
