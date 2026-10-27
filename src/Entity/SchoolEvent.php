<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SchoolEventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SchoolEventRepository::class)]
class SchoolEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private AcademicYear $academicYear;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $endTime;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    #[ORM\Column]
    private bool $general = false;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\JoinTable(name: 'school_event_group')]
    private Collection $groups;

    /** Tipo del catálogo del centro. Obligatorio al guardar desde el formulario (las filas antiguas lo traen por migración). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?SchoolEventType $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $organizingDepartment = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $coordinator = null;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'school_event_companion')]
    private Collection $companions;

    /**
     * Estudiantes que participan, opcionalmente, de entre los de los grupos asignados. Solo se guarda: de momento
     * no se muestra en ninguna pantalla.
     *
     * @var Collection<int, Student>
     */
    #[ORM\ManyToMany(targetEntity: Student::class)]
    #[ORM\JoinTable(name: 'school_event_student')]
    private Collection $students;

    /** Quien la publicó: un docente sin gestión solo puede ver, editar y eliminar las suyas. Null en las anteriores a este dato. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $createdBy = null;

    public function getCreatedBy(): ?Teacher
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?Teacher $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function __construct()
    {
        $this->groups     = new ArrayCollection();
        $this->companions = new ArrayCollection();
        $this->students   = new ArrayCollection();
    }

    /** @return Collection<int, Student> */
    public function getStudents(): Collection
    {
        return $this->students;
    }

    public function addStudent(Student $student): static
    {
        if (!$this->students->contains($student)) {
            $this->students->add($student);
        }

        return $this;
    }

    public function removeStudent(Student $student): static
    {
        $this->students->removeElement($student);

        return $this;
    }

    public function getType(): ?SchoolEventType
    {
        return $this->type;
    }

    public function setType(?SchoolEventType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getOrganizingDepartment(): ?string
    {
        return $this->organizingDepartment;
    }

    public function setOrganizingDepartment(?string $organizingDepartment): static
    {
        $this->organizingDepartment = $organizingDepartment;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getCoordinator(): ?Teacher
    {
        return $this->coordinator;
    }

    public function setCoordinator(?Teacher $coordinator): static
    {
        $this->coordinator = $coordinator;

        return $this;
    }

    /** @return Collection<int, Teacher> */
    public function getCompanions(): Collection
    {
        return $this->companions;
    }

    public function addCompanion(Teacher $teacher): static
    {
        if (!$this->companions->contains($teacher)) {
            $this->companions->add($teacher);
        }

        return $this;
    }

    public function removeCompanion(Teacher $teacher): static
    {
        $this->companions->removeElement($teacher);

        return $this;
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

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(\DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): \DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(\DateTimeImmutable $endTime): static
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function isGeneral(): bool
    {
        return $this->general;
    }

    public function setGeneral(bool $general): static
    {
        $this->general = $general;

        return $this;
    }

    /** @return Collection<int, Group> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(Group $group): static
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }

        return $this;
    }

    public function removeGroup(Group $group): static
    {
        $this->groups->removeElement($group);

        return $this;
    }
}
