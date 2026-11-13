<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * Suscripción de un dispositivo a los avisos push de un docente (Web Push). El navegador la crea al activar los
 * avisos; `endpoint`, `p256dh` y `authToken` bastan para enviarle notificaciones y se tratan como secretos
 * (nunca se muestran). Es única por dispositivo (hash del endpoint).
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_push_subscription_endpoint', columns: ['endpoint_hash'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Teacher $teacher;

    #[ORM\Column(length: 64)]
    private string $endpointHash;

    #[ORM\Column(type: Types::TEXT)]
    private string $endpoint;

    #[ORM\Column(length: 255)]
    private string $p256dh;

    #[ORM\Column(length: 255)]
    private string $authToken;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Teacher $teacher, string $endpoint, string $p256dh, string $authToken, ?string $userAgent = null)
    {
        $this->teacher      = $teacher;
        $this->endpoint     = $endpoint;
        $this->endpointHash = self::hashEndpoint($endpoint);
        $this->p256dh       = $p256dh;
        $this->authToken    = $authToken;
        $this->userAgent    = $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;
        $this->createdAt    = now();
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTeacher(): Teacher
    {
        return $this->teacher;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function getAuthToken(): string
    {
        return $this->authToken;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Un dispositivo que cambia de usuario en el mismo navegador pasa a ser del nuevo docente. */
    public function reassignTo(Teacher $teacher, string $p256dh, string $authToken): static
    {
        $this->teacher   = $teacher;
        $this->p256dh    = $p256dh;
        $this->authToken = $authToken;

        return $this;
    }
}
