<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ResourceBookingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Reserva de un recurso compartido un día, en uno o varios tramos horarios, a nombre de un docente. */
#[ORM\Entity(repositoryClass: ResourceBookingRepository::class)]
#[ORM\Index(name: 'idx_resource_booking_date', columns: ['date'])]
#[ORM\Index(name: 'idx_resource_booking_series', columns: ['series_id'])]
class ResourceBooking
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
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SharedResource $resource;

    /** Docente a cuyo nombre está la reserva. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Teacher $teacher;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** @var Collection<int, TimeSlot> */
    #[ORM\ManyToMany(targetEntity: TimeSlot::class)]
    #[ORM\JoinTable(name: 'resource_booking_time_slot')]
    private Collection $timeSlots;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'resource_booking_group')]
    private Collection $groups;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 16, enumType: ResourceBookingStatus::class)]
    private ResourceBookingStatus $status = ResourceBookingStatus::Approved;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $rejectionReason = null;

    /** Las reservas creadas juntas con una periodicidad semanal comparten este identificador. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $seriesId = null;

    /** Falso mientras el docente no ha visto cómo se resolvió su solicitud (aprobada o rechazada): lo avisa la campana. */
    #[ORM\Column]
    private bool $resolutionSeen = true;

    public function __construct()
    {
        $this->timeSlots = new ArrayCollection();
        $this->groups    = new ArrayCollection();
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

    public function getResource(): SharedResource
    {
        return $this->resource;
    }

    public function setResource(SharedResource $resource): static
    {
        $this->resource = $resource;

        return $this;
    }

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }

    public function setTeacher(Teacher $teacher): static
    {
        $this->teacher = $teacher;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    /**
     * @return Collection<int, TimeSlot>
     */
    public function getTimeSlots(): Collection
    {
        return $this->timeSlots;
    }

    /**
     * Los tramos ordenados por hora de inicio.
     *
     * @return list<TimeSlot>
     */
    public function getTimeSlotsOrdered(): array
    {
        $slots = $this->timeSlots->toArray();
        usort($slots, static fn (TimeSlot $a, TimeSlot $b): int => $a->getStartTime() <=> $b->getStartTime());

        return $slots;
    }

    /**
     * @param iterable<TimeSlot> $slots
     */
    public function setTimeSlots(iterable $slots): static
    {
        $this->timeSlots->clear();
        foreach ($slots as $slot) {
            if (!$this->timeSlots->contains($slot)) {
                $this->timeSlots->add($slot);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Group>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    /**
     * @param iterable<Group> $groups
     */
    public function setGroups(iterable $groups): static
    {
        $this->groups->clear();
        foreach ($groups as $group) {
            if (!$this->groups->contains($group)) {
                $this->groups->add($group);
            }
        }

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): ResourceBookingStatus
    {
        return $this->status;
    }

    public function setStatus(ResourceBookingStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === ResourceBookingStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === ResourceBookingStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === ResourceBookingStatus::Rejected;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;

        return $this;
    }

    public function getSeriesId(): ?Uuid
    {
        return $this->seriesId;
    }

    public function setSeriesId(?Uuid $seriesId): static
    {
        $this->seriesId = $seriesId;

        return $this;
    }

    public function isResolutionSeen(): bool
    {
        return $this->resolutionSeen;
    }

    public function setResolutionSeen(bool $resolutionSeen): static
    {
        $this->resolutionSeen = $resolutionSeen;

        return $this;
    }
}
