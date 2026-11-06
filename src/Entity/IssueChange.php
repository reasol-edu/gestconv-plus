<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Una entrada del historial de una incidencia: un campo (el estado incluido) que cambió, con su valor antiguo y el nuevo
 * tal como se veían en ese momento (nombres, no identificadores: los tipos o las ubicaciones pueden renombrarse después).
 */
#[ORM\Entity]
#[ORM\Table(name: 'issue_change')]
class IssueChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'changes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Issue $issue;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $author;

    /** Campo que cambió: status, priority, type, location, description, duplicate_of o created. */
    #[ORM\Column(length: 40)]
    private string $field;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oldValue;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $newValue;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Issue $issue, ?Teacher $author, string $field, ?string $oldValue, ?string $newValue, \DateTimeImmutable $createdAt)
    {
        $this->issue     = $issue;
        $this->author    = $author;
        $this->field     = $field;
        $this->oldValue  = $oldValue;
        $this->newValue  = $newValue;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getAuthor(): ?Teacher
    {
        return $this->author;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
