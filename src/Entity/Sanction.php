<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SanctionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;


#[ORM\Entity(repositoryClass: SanctionRepository::class)]
class Sanction
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private AcademicYear $academicYear;

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
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, IncidentReport> */
    #[ORM\OneToMany(targetEntity: IncidentReport::class, mappedBy: 'sanction')]
    private Collection $reports;

    /** @var Collection<int, SanctionMeasure> */
    #[ORM\ManyToMany(targetEntity: SanctionMeasure::class)]
    #[ORM\JoinTable(name: 'sanction_sanction_measure')]
    private Collection $measures;

    #[ORM\Column(type: Types::TEXT)]
    private string $details;

    #[ORM\Column]
    private bool $noMeasureApplied = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $noMeasureReason = null;

    #[ORM\Column(nullable: true)]
    private ?bool $measuresEffective = null;

    #[ORM\Column(nullable: true)]
    private ?bool $familyClaimed = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $familyClaimAttitude = null;

    #[ORM\Column(nullable: true)]
    private ?bool $registeredInSeneca = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $calendarLabel = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $effectiveTo = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Communication $notifiedCommunication = null;

    /** @var Collection<int, Communication> */
    #[ORM\OneToMany(targetEntity: Communication::class, mappedBy: 'sanction', orphanRemoval: true)]
    private Collection $communications;

    /** @var Collection<int, SanctionObservation> */
    #[ORM\OneToMany(targetEntity: SanctionObservation::class, mappedBy: 'sanction', orphanRemoval: true)]
    private Collection $observations;

    /** @var Collection<int, SanctionTask> */
    #[ORM\OneToMany(targetEntity: SanctionTask::class, mappedBy: 'sanction', orphanRemoval: true, cascade: ['persist'])]
    private Collection $tasks;

    public function __construct()
    {
        $this->createdAt      = new \DateTimeImmutable();
        $this->reports        = new ArrayCollection();
        $this->measures       = new ArrayCollection();
        $this->communications = new ArrayCollection();
        $this->observations   = new ArrayCollection();
        $this->tasks          = new ArrayCollection();
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, IncidentReport> */
    public function getReports(): Collection
    {
        return $this->reports;
    }

    /** @return Collection<int, SanctionMeasure> */
    public function getMeasures(): Collection
    {
        return $this->measures;
    }

    public function addMeasure(SanctionMeasure $measure): static
    {
        if (!$this->measures->contains($measure)) {
            $this->measures->add($measure);
        }

        return $this;
    }

    public function removeMeasure(SanctionMeasure $measure): static
    {
        $this->measures->removeElement($measure);

        return $this;
    }

    public function getDetails(): string
    {
        return $this->details;
    }

    public function setDetails(string $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function isNoMeasureApplied(): bool
    {
        return $this->noMeasureApplied;
    }

    public function setNoMeasureApplied(bool $noMeasureApplied): static
    {
        $this->noMeasureApplied = $noMeasureApplied;

        return $this;
    }

    public function getNoMeasureReason(): ?string
    {
        return $this->noMeasureReason;
    }

    public function setNoMeasureReason(?string $noMeasureReason): static
    {
        $this->noMeasureReason = $noMeasureReason;

        return $this;
    }

    public function getEffectiveFrom(): ?\DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function setEffectiveFrom(?\DateTimeImmutable $effectiveFrom): static
    {
        $this->effectiveFrom = $effectiveFrom;

        return $this;
    }

    public function getEffectiveTo(): ?\DateTimeImmutable
    {
        return $this->effectiveTo;
    }

    public function setEffectiveTo(?\DateTimeImmutable $effectiveTo): static
    {
        $this->effectiveTo = $effectiveTo;

        return $this;
    }

    public function requiresDates(): bool
    {
        foreach ($this->measures as $measure) {
            if ($measure->hasDateRange()) {
                return true;
            }
        }

        return false;
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

    public function getMeasuresEffective(): ?bool
    {
        return $this->measuresEffective;
    }

    public function setMeasuresEffective(?bool $measuresEffective): static
    {
        $this->measuresEffective = $measuresEffective;

        return $this;
    }

    public function isFamilyClaimed(): ?bool
    {
        return $this->familyClaimed;
    }

    public function setFamilyClaimed(?bool $familyClaimed): static
    {
        $this->familyClaimed = $familyClaimed;

        return $this;
    }

    public function getFamilyClaimAttitude(): ?string
    {
        return $this->familyClaimAttitude;
    }

    public function setFamilyClaimAttitude(?string $familyClaimAttitude): static
    {
        $this->familyClaimAttitude = $familyClaimAttitude;

        return $this;
    }

    public function isRegisteredInSeneca(): ?bool
    {
        return $this->registeredInSeneca;
    }

    public function setRegisteredInSeneca(?bool $registeredInSeneca): static
    {
        $this->registeredInSeneca = $registeredInSeneca;

        return $this;
    }

    public function getCalendarLabel(): ?string
    {
        return $this->calendarLabel;
    }

    public function setCalendarLabel(?string $calendarLabel): static
    {
        $this->calendarLabel = $calendarLabel;

        return $this;
    }

    /** @return Collection<int, SanctionObservation> */
    public function getObservations(): Collection
    {
        return $this->observations;
    }

    /** @return Collection<int, SanctionTask> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function hasIncompleteTasks(): bool
    {
        return $this->tasks->exists(static fn (int $k, SanctionTask $t): bool => $t->getCompletedAt() === null);
    }
}
