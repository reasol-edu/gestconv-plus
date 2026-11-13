<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Incidencia: algo que ha ocurrido y debe atender el responsable de su tipo (una avería, un problema con una dependencia
 * o con un recurso reservado…). Tiene observaciones, adjuntos y un historial de cambios.
 */
#[ORM\Entity(repositoryClass: IssueRepository::class)]
#[ORM\Table(name: 'issue')]
#[ORM\UniqueConstraint(name: 'uq_issue_centre_number', columns: ['educational_centre_id', 'number'])]
#[ORM\Index(name: 'idx_issue_status', columns: ['status'])]
#[ORM\Index(name: 'idx_issue_created', columns: ['created_at'])]
class Issue
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private AcademicYear $academicYear;

    /** Número correlativo dentro del centro, para nombrarla («#42»). */
    #[ORM\Column]
    private int $number = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private IssueType $type;

    /** Dependencia (del catálogo de ubicaciones); vacía cuando la incidencia nace de una reserva, que ya lo incluye. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LocationOption $location = null;

    /** Reserva relacionada: solo si la crea desde ella el docente que la hizo. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ResourceBooking $booking = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Teacher $reporter;

    /** Persona a quien se ha asignado (opcional): puede observar y cambiar la prioridad y el estado. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $assignee = null;

    /** Lo ocurrido, en texto enriquecido (HTML que se sanea al mostrarlo). */
    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(length: 20, enumType: IssueStatus::class)]
    private IssueStatus $status = IssueStatus::Open;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?IssuePriority $priority = null;

    /** La incidencia original, si esta se cerró por duplicidad. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Issue $duplicateOf = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** Fecha límite recomendada, en días lectivos desde que se asignó la prioridad (null = no procede). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recommendedDue = null;

    /** Fecha límite máxima, en días lectivos desde que se asignó la prioridad (null = no procede). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $maxDue = null;

    /** @var Collection<int, IssueObservation> */
    #[ORM\OneToMany(targetEntity: IssueObservation::class, mappedBy: 'issue', orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => \SortDirection::Ascending])]
    private Collection $observations;

    /** @var Collection<int, IssueAttachment> */
    #[ORM\OneToMany(targetEntity: IssueAttachment::class, mappedBy: 'issue', orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => \SortDirection::Ascending])]
    private Collection $attachments;

    /** @var Collection<int, IssueChange> */
    #[ORM\OneToMany(targetEntity: IssueChange::class, mappedBy: 'issue', orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => \SortDirection::Ascending])]
    private Collection $changes;

    public function __construct(\DateTimeImmutable $now)
    {
        $this->createdAt    = $now;
        $this->updatedAt    = $now;
        $this->observations = new ArrayCollection();
        $this->attachments  = new ArrayCollection();
        $this->changes      = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function setEducationalCentre(EducationalCentre $educationalCentre): static
    {
        $this->educationalCentre = $educationalCentre;

        return $this;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): static
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function setNumber(int $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getType(): IssueType
    {
        return $this->type;
    }

    public function setType(IssueType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getLocation(): ?LocationOption
    {
        return $this->location;
    }

    public function setLocation(?LocationOption $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getBooking(): ?ResourceBooking
    {
        return $this->booking;
    }

    public function setBooking(?ResourceBooking $booking): static
    {
        $this->booking = $booking;

        return $this;
    }

    public function getReporter(): Teacher
    {
        return $this->reporter;
    }

    public function setReporter(Teacher $reporter): static
    {
        $this->reporter = $reporter;

        return $this;
    }

    public function getAssignee(): ?Teacher
    {
        return $this->assignee;
    }

    public function setAssignee(?Teacher $assignee): static
    {
        $this->assignee = $assignee;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): IssueStatus
    {
        return $this->status;
    }

    public function setStatus(IssueStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPriority(): ?IssuePriority
    {
        return $this->priority;
    }

    public function setPriority(?IssuePriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getDuplicateOf(): ?Issue
    {
        return $this->duplicateOf;
    }

    public function setDuplicateOf(?Issue $duplicateOf): static
    {
        $this->duplicateOf = $duplicateOf;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $now): static
    {
        $this->updatedAt = $now;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function getRecommendedDue(): ?\DateTimeImmutable
    {
        return $this->recommendedDue;
    }

    public function setRecommendedDue(?\DateTimeImmutable $recommendedDue): static
    {
        $this->recommendedDue = $recommendedDue;

        return $this;
    }

    public function getMaxDue(): ?\DateTimeImmutable
    {
        return $this->maxDue;
    }

    public function setMaxDue(?\DateTimeImmutable $maxDue): static
    {
        $this->maxDue = $maxDue;

        return $this;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** Sigue abierta y ha pasado su plazo máximo (la fecha límite es el último día en que se puede resolver). */
    public function isOverdue(\DateTimeImmutable $today): bool
    {
        return $this->isOpen() && $this->maxDue !== null && $this->maxDue->format('Y-m-d') < $today->format('Y-m-d');
    }

    /** @return Collection<int, IssueObservation> */
    public function getObservations(): Collection
    {
        return $this->observations;
    }

    public function addObservation(IssueObservation $observation): static
    {
        if (!$this->observations->contains($observation)) {
            $this->observations->add($observation);
        }

        return $this;
    }

    /** @return Collection<int, IssueAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(IssueAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
        }

        return $this;
    }

    public function removeAttachment(IssueAttachment $attachment): static
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }

    /** @return Collection<int, IssueChange> */
    public function getChanges(): Collection
    {
        return $this->changes;
    }

    public function addChange(IssueChange $change): static
    {
        if (!$this->changes->contains($change)) {
            $this->changes->add($change);
        }

        return $this;
    }
}
