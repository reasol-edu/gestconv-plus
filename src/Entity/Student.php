<?php
namespace App\Entity;

use App\Repository\StudentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StudentRepository::class)]
class Student
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Embedded(class: PersonName::class)]
    private PersonName $name;

    #[ORM\Column(length: 50)]
    private string $studentId;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tutorName1 = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $tutorEmail1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tutorName2 = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $tutorEmail2 = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $contactPhone1 = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contactPhone1Notes = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $contactPhone2 = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contactPhone2Notes = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $contactPhone3 = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contactPhone3Notes = null;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class, inversedBy: 'students', fetch: 'EXTRA_LAZY')]
    #[ORM\JoinTable(name: 'student_groups')]
    private Collection $groups;

    public function __construct(PersonName $name)
    {
        $this->name = $name;
        $this->groups = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): PersonName
    {
        return $this->name;
    }

    public function setName(PersonName $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getStudentId(): string
    {
        return $this->studentId;
    }

    public function setStudentId(string $studentId): static
    {
        $this->studentId = $studentId;

        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;

        return $this;
    }

    public function getTutorName1(): ?string { return $this->tutorName1; }
    public function setTutorName1(?string $v): static { $this->tutorName1 = $v; return $this; }

    public function getTutorEmail1(): ?string { return $this->tutorEmail1; }
    public function setTutorEmail1(?string $v): static { $this->tutorEmail1 = $v; return $this; }

    public function getTutorName2(): ?string { return $this->tutorName2; }
    public function setTutorName2(?string $v): static { $this->tutorName2 = $v; return $this; }

    public function getTutorEmail2(): ?string { return $this->tutorEmail2; }
    public function setTutorEmail2(?string $v): static { $this->tutorEmail2 = $v; return $this; }

    public function getContactPhone1(): ?string { return $this->contactPhone1; }
    public function setContactPhone1(?string $v): static { $this->contactPhone1 = $v; return $this; }

    public function getContactPhone1Notes(): ?string { return $this->contactPhone1Notes; }
    public function setContactPhone1Notes(?string $v): static { $this->contactPhone1Notes = $v; return $this; }

    public function getContactPhone2(): ?string { return $this->contactPhone2; }
    public function setContactPhone2(?string $v): static { $this->contactPhone2 = $v; return $this; }

    public function getContactPhone2Notes(): ?string { return $this->contactPhone2Notes; }
    public function setContactPhone2Notes(?string $v): static { $this->contactPhone2Notes = $v; return $this; }

    public function getContactPhone3(): ?string { return $this->contactPhone3; }
    public function setContactPhone3(?string $v): static { $this->contactPhone3 = $v; return $this; }

    public function getContactPhone3Notes(): ?string { return $this->contactPhone3Notes; }
    public function setContactPhone3Notes(?string $v): static { $this->contactPhone3Notes = $v; return $this; }

    /**
     * @return Collection<int, Group>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
            $group->addStudent($this);
        }

        return $this;
    }

    public function removeGroup(Group $group): static
    {
        if ($this->groups->removeElement($group)) {
            $group->removeStudent($this);
        }

        return $this;
    }
}
