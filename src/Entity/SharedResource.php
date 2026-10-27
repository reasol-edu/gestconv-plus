<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SharedResourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Recurso compartido reservable («Aula de informática», «Carro de portátiles 1»…), dentro de una categoría. */
#[ORM\Entity(repositoryClass: SharedResourceRepository::class)]
class SharedResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ResourceCategory $category;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    /** Un recurso inactivo no admite reservas nuevas, pero conserva las existentes. */
    #[ORM\Column]
    private bool $active = true;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCategory(): ResourceCategory
    {
        return $this->category;
    }

    public function setCategory(ResourceCategory $category): static
    {
        $this->category = $category;

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

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

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
}
