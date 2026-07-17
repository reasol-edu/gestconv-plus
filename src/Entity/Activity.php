<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActivityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ActivityRepository::class)]
class Activity
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'activities')]
    #[ORM\JoinColumn(nullable: false)]
    private Absence $absence;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private TimeSlot $timeSlot;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** @var Collection<int, GroupTeacher> */
    #[ORM\ManyToMany(targetEntity: GroupTeacher::class)]
    #[ORM\JoinTable(name: 'activity_group_teacher')]
    private Collection $subjects;

    /** @var Collection<int, ActivityAttachment> */
    #[ORM\OneToMany(targetEntity: ActivityAttachment::class, mappedBy: 'activity', orphanRemoval: true, fetch: 'EXTRA_LAZY', cascade: ['persist'])]
    private Collection $attachments;

    public function __construct()
    {
        $this->subjects    = new ArrayCollection();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAbsence(): Absence
    {
        return $this->absence;
    }

    public function setAbsence(Absence $absence): static
    {
        $this->absence = $absence;

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

    public function getTimeSlot(): TimeSlot
    {
        return $this->timeSlot;
    }

    public function setTimeSlot(TimeSlot $timeSlot): static
    {
        $this->timeSlot = $timeSlot;

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

    /** @return Collection<int, GroupTeacher> */
    public function getSubjects(): Collection
    {
        return $this->subjects;
    }

    public function addSubject(GroupTeacher $subject): static
    {
        if (!$this->subjects->contains($subject)) {
            $this->subjects->add($subject);
        }

        return $this;
    }

    public function removeSubject(GroupTeacher $subject): static
    {
        $this->subjects->removeElement($subject);

        return $this;
    }

    /** @return Collection<int, ActivityAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(ActivityAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
        }

        return $this;
    }

    public function removeAttachment(ActivityAttachment $attachment): static
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }
}
