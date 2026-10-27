<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ResourceCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Categoría de recursos compartidos reservables («Dependencias», «Carros de portátiles»…), configurable por centro.
 * Sus responsables gestionan las reservas de todos sus recursos.
 */
#[ORM\Entity(repositoryClass: ResourceCategoryRepository::class)]
class ResourceCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\Column(length: 200)]
    private string $name;

    /** Color base del distintivo (#rrggbb); null = gris neutro. */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\Column]
    private bool $active = true;

    /** Solo sus responsables y la dirección pueden reservar sus recursos. */
    #[ORM\Column]
    private bool $restricted = false;

    /** Las reservas de docentes que no gestionan la categoría quedan pendientes hasta que un responsable las valida. */
    #[ORM\Column]
    private bool $requiresApproval = false;

    /** Días máximos de antelación para reservar; null = el ajuste del centro (bookings.max_advance_days). */
    #[ORM\Column(nullable: true)]
    private ?int $maxAdvanceDays = null;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class)]
    #[ORM\JoinTable(name: 'resource_category_manager')]
    private Collection $managers;

    public function __construct()
    {
        $this->managers = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function isRestricted(): bool
    {
        return $this->restricted;
    }

    public function setRestricted(bool $restricted): static
    {
        $this->restricted = $restricted;

        return $this;
    }

    public function requiresApproval(): bool
    {
        return $this->requiresApproval;
    }

    public function setRequiresApproval(bool $requiresApproval): static
    {
        $this->requiresApproval = $requiresApproval;

        return $this;
    }

    public function getMaxAdvanceDays(): ?int
    {
        return $this->maxAdvanceDays;
    }

    public function setMaxAdvanceDays(?int $maxAdvanceDays): static
    {
        $this->maxAdvanceDays = $maxAdvanceDays;

        return $this;
    }

    /**
     * @return Collection<int, Teacher>
     */
    public function getManagers(): Collection
    {
        return $this->managers;
    }

    public function addManager(Teacher $teacher): static
    {
        if (!$this->managers->contains($teacher)) {
            $this->managers->add($teacher);
        }

        return $this;
    }

    public function removeManager(Teacher $teacher): static
    {
        $this->managers->removeElement($teacher);

        return $this;
    }

    public function isManagedBy(Teacher $teacher): bool
    {
        return $this->managers->contains($teacher);
    }
}
