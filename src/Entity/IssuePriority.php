<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IssuePriorityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Prioridad de una incidencia, configurable en cada centro y ordenada de más a menos importante (por `position`).
 * Lleva un color y dos plazos de resolución, en días lectivos: el recomendado y el máximo (0 = no procede).
 */
#[ORM\Entity(repositoryClass: IssuePriorityRepository::class)]
#[ORM\Table(name: 'issue_priority')]
class IssuePriority
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EducationalCentre $educationalCentre;

    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(length: 7)]
    private string $color = '#6b7280';

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $recommendedDays = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $maxDays = 0;

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

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = preg_match('/^#[0-9a-f]{6}$/i', $color) === 1 ? strtolower($color) : '#6b7280';

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /** Días lectivos recomendados para resolverla; 0 = no procede. */
    public function getRecommendedDays(): int
    {
        return $this->recommendedDays;
    }

    public function setRecommendedDays(int $recommendedDays): static
    {
        $this->recommendedDays = max(0, min(365, $recommendedDays));

        return $this;
    }

    /** Días lectivos máximos para resolverla; 0 = no procede. */
    public function getMaxDays(): int
    {
        return $this->maxDays;
    }

    public function setMaxDays(int $maxDays): static
    {
        $this->maxDays = max(0, min(365, $maxDays));

        return $this;
    }
}
