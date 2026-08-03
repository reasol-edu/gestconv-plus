<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Catalog\CatalogEntryInterface;
use App\Repository\DailyNoteTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DailyNoteTypeRepository::class)]
class DailyNoteType implements CatalogEntryInterface
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

    /** Ocurrencias activas de este tipo que, para un mismo estudiante, dan lugar a un aviso de parte. 0 = nunca. */
    #[ORM\Column]
    private int $occurrencesForReport = 0;

    /** Días desde el registro tras los que una nota de este tipo se desactiva automáticamente. 0 = no caduca nunca. */
    #[ORM\Column]
    private int $expiryDays = 0;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

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

    public function getOccurrencesForReport(): int
    {
        return $this->occurrencesForReport;
    }

    public function setOccurrencesForReport(int $occurrencesForReport): static
    {
        $this->occurrencesForReport = max(0, $occurrencesForReport);

        return $this;
    }

    public function getExpiryDays(): int
    {
        return $this->expiryDays;
    }

    public function setExpiryDays(int $expiryDays): static
    {
        $this->expiryDays = max(0, $expiryDays);

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
