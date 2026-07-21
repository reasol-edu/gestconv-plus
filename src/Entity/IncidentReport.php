<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\AcademicYear;
use App\Repository\IncidentReportRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IncidentReportRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_ir_year_number', columns: ['academic_year_id', 'number'])]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_incident_report_occurred')]
class IncidentReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private AcademicYear $academicYear;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $number;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Student $student;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Group $group;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Teacher $registeredBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LocationOption $location = null;

    /** @var Collection<int, IncidentBehavior> */
    #[ORM\ManyToMany(targetEntity: IncidentBehavior::class)]
    #[ORM\JoinTable(name: 'incident_report_behavior')]
    private Collection $behaviors;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column]
    private bool $expelledFromClass = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $assignedTasks = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true, enumType: TasksCompletionStatus::class)]
    private ?TasksCompletionStatus $tasksCompleted = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $prescribedAt = null;

    #[ORM\ManyToOne(inversedBy: 'reports')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Sanction $sanction = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Communication $notifiedCommunication = null;

    /** @var Collection<int, Communication> */
    #[ORM\OneToMany(targetEntity: Communication::class, mappedBy: 'incidentReport', orphanRemoval: true)]
    private Collection $communications;

    /** @var Collection<int, IncidentReportObservation> */
    #[ORM\OneToMany(targetEntity: IncidentReportObservation::class, mappedBy: 'incidentReport', orphanRemoval: true)]
    private Collection $observations;

    public function __construct()
    {
        $this->behaviors      = new ArrayCollection();
        $this->communications = new ArrayCollection();
        $this->observations   = new ArrayCollection();
        $this->createdAt      = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getStudent(): Student
    {
        return $this->student;
    }

    public function setStudent(Student $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getGroup(): Group
    {
        return $this->group;
    }

    public function setGroup(Group $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function getRegisteredBy(): Teacher
    {
        return $this->registeredBy;
    }

    public function setRegisteredBy(Teacher $registeredBy): static
    {
        $this->registeredBy = $registeredBy;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

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

    /**
     * @return Collection<int, IncidentBehavior>
     */
    public function getBehaviors(): Collection
    {
        return $this->behaviors;
    }

    public function addBehavior(IncidentBehavior $behavior): static
    {
        if (!$this->behaviors->contains($behavior)) {
            $this->behaviors->add($behavior);
        }

        return $this;
    }

    public function removeBehavior(IncidentBehavior $behavior): static
    {
        $this->behaviors->removeElement($behavior);

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

    public function isExpelledFromClass(): bool
    {
        return $this->expelledFromClass;
    }

    public function setExpelledFromClass(bool $expelledFromClass): static
    {
        $this->expelledFromClass = $expelledFromClass;

        return $this;
    }

    public function getAssignedTasks(): ?string
    {
        return $this->assignedTasks;
    }

    public function setAssignedTasks(?string $assignedTasks): static
    {
        $this->assignedTasks = $assignedTasks;

        return $this;
    }

    public function getTasksCompleted(): ?TasksCompletionStatus
    {
        return $this->tasksCompleted;
    }

    public function setTasksCompleted(?TasksCompletionStatus $tasksCompleted): static
    {
        $this->tasksCompleted = $tasksCompleted;

        return $this;
    }

    public function getPrescribedAt(): ?\DateTimeImmutable
    {
        return $this->prescribedAt;
    }

    public function setPrescribedAt(?\DateTimeImmutable $prescribedAt): static
    {
        $this->prescribedAt = $prescribedAt;

        return $this;
    }

    public function isPrescribed(): bool
    {
        return $this->prescribedAt !== null;
    }

    public function getSanction(): ?Sanction
    {
        return $this->sanction;
    }

    public function setSanction(?Sanction $sanction): static
    {
        $this->sanction = $sanction;

        return $this;
    }

    public function getNotifiedCommunication(): ?Communication
    {
        return $this->notifiedCommunication;
    }

    public function setNotifiedCommunication(?Communication $notifiedCommunication): static
    {
        $this->notifiedCommunication = $notifiedCommunication;

        return $this;
    }

    public function isNotified(): bool
    {
        return $this->notifiedCommunication !== null;
    }

    /** @return Collection<int, Communication> */
    public function getCommunications(): Collection
    {
        return $this->communications;
    }

    /** @return Collection<int, IncidentReportObservation> */
    public function getObservations(): Collection
    {
        return $this->observations;
    }
}
