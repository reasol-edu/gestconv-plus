<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActivityAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ActivityAttachmentRepository::class)]
class ActivityAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false)]
    private Activity $activity;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $size;

    /** @var resource|string */
    #[ORM\Column(type: Types::BLOB)]
    private $content;

    public function __construct(Activity $activity, string $filename, string $mimeType, int $size, string $content)
    {
        $this->activity = $activity;
        $this->filename = $filename;
        $this->mimeType = $mimeType;
        $this->size     = $size;
        $this->content  = $content;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getActivity(): Activity
    {
        return $this->activity;
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

    public function getContent(): string
    {
        return is_resource($this->content) ? (string) stream_get_contents($this->content) : (string) $this->content;
    }
}
