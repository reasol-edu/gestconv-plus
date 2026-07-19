<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SanctionTaskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SanctionTaskRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_sanction_task_group_teacher', columns: ['sanction_id', 'group_teacher_id'])]
class SanctionTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false)]
    private Sanction $sanction;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private GroupTeacher $groupTeacher;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $notApplicable = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** @var Collection<int, SanctionTaskAttachment> */
    #[ORM\OneToMany(targetEntity: SanctionTaskAttachment::class, mappedBy: 'task', orphanRemoval: true, fetch: 'EXTRA_LAZY', cascade: ['persist'])]
    private Collection $attachments;

    public function __construct(Sanction $sanction, GroupTeacher $groupTeacher)
    {
        $this->sanction     = $sanction;
        $this->groupTeacher = $groupTeacher;
        $this->attachments  = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSanction(): Sanction
    {
        return $this->sanction;
    }

    public function getGroupTeacher(): GroupTeacher
    {
        return $this->groupTeacher;
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

    public function isNotApplicable(): bool
    {
        return $this->notApplicable;
    }

    public function setNotApplicable(bool $notApplicable): static
    {
        $this->notApplicable = $notApplicable;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->completedAt !== null;
    }

    /** @return Collection<int, SanctionTaskAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(SanctionTaskAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
        }

        return $this;
    }

    public function removeAttachment(SanctionTaskAttachment $attachment): static
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }
}
