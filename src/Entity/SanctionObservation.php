<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SanctionObservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SanctionObservationRepository::class)]
class SanctionObservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'observations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Sanction $sanction;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Teacher $registeredBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $registeredAt;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Sanction $sanction,
        Teacher $registeredBy,
        \DateTimeImmutable $registeredAt,
        string $text,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->sanction     = $sanction;
        $this->registeredBy = $registeredBy;
        $this->registeredAt = $registeredAt;
        $this->text         = $text;
        $this->createdAt    = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSanction(): Sanction
    {
        return $this->sanction;
    }

    public function getRegisteredBy(): Teacher
    {
        return $this->registeredBy;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setRegisteredAt(\DateTimeImmutable $registeredAt): static
    {
        $this->registeredAt = $registeredAt;

        return $this;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }
}
