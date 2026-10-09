<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailDigestItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One line waiting for the recipient's daily digest email (see {@see \App\Service\EmailDigestSender}).
 * It stores the translation parameters rather than rendered text, so the digest is rendered when sent.
 */
#[ORM\Entity(repositoryClass: EmailDigestItemRepository::class)]
#[ORM\Table(name: 'email_digest_item')]
#[ORM\Index(columns: ['recipient_id', 'sent_at'], name: 'idx_edi_recipient_sent')]
#[ORM\Index(columns: ['educational_centre_id'], name: 'idx_edi_centre')]
class EmailDigestItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /**
     * @param array<string, scalar|null> $params translation parameters of the line (keys include the % signs)
     */
    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private EducationalCentre $educationalCentre,
        #[ORM\ManyToOne(targetEntity: Teacher::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Teacher $recipient,
        #[ORM\Column(length: 50)]
        private string $eventKey,
        #[ORM\Column(type: Types::JSON)]
        private array $params,
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $url,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $sentAt = null,
    ) {}

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEducationalCentre(): EducationalCentre
    {
        return $this->educationalCentre;
    }

    public function getRecipient(): Teacher
    {
        return $this->recipient;
    }

    public function getEventKey(): string
    {
        return $this->eventKey;
    }

    /** @return array<string, scalar|null> */
    public function getParams(): array
    {
        return $this->params;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markSent(\DateTimeImmutable $at): void
    {
        $this->sentAt = $at;
    }
}
