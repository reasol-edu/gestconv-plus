<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Fichero adjunto a una incidencia: una imagen (con su miniatura) u otro documento. */
#[ORM\Entity]
#[ORM\Table(name: 'issue_attachment')]
class IssueAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Issue $issue;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $uploadedBy;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $size;

    /** @var resource|string */
    #[ORM\Column(type: Types::BLOB)]
    private $content;

    /**
     * Miniatura JPEG de las imágenes (null en los demás ficheros o si no se pudo generar).
     *
     * @var resource|string|null
     */
    #[ORM\Column(type: Types::BLOB, nullable: true)]
    private $thumbnail = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Issue $issue, ?Teacher $uploadedBy, string $filename, string $mimeType, int $size, string $content, ?string $thumbnail, \DateTimeImmutable $createdAt)
    {
        $this->issue      = $issue;
        $this->uploadedBy = $uploadedBy;
        $this->filename   = $filename;
        $this->mimeType   = $mimeType;
        $this->size       = $size;
        $this->content    = $content;
        $this->thumbnail  = $thumbnail;
        $this->createdAt  = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getUploadedBy(): ?Teacher
    {
        return $this->uploadedBy;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    /** Los BLOB llegan como flujo (SQLite, PostgreSQL) que solo se puede leer una vez: se lee y se guarda como texto. */
    public function getContent(): string
    {
        $content       = is_resource($this->content) ? (string) stream_get_contents($this->content) : (string) $this->content;
        $this->content = $content;

        return $content;
    }

    public function hasThumbnail(): bool
    {
        return $this->thumbnail !== null;
    }

    public function getThumbnail(): ?string
    {
        if ($this->thumbnail === null) {
            return null;
        }

        $thumbnail       = is_resource($this->thumbnail) ? (string) stream_get_contents($this->thumbnail) : (string) $this->thumbnail;
        $this->thumbnail = $thumbnail;

        return $thumbnail;
    }

    public function isImage(): bool
    {
        return strncmp($this->mimeType, 'image/', 6) === 0;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
