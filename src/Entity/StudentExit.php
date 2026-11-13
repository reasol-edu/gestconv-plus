<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StudentExitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * Salida del aula autorizada por un docente. Mientras `returnedAt` es null el estudiante
 * sigue fuera.
 */
#[ORM\Entity(repositoryClass: StudentExitRepository::class)]
#[ORM\Index(columns: ['academic_year_id', 'returned_at'], name: 'idx_student_exit_year_returned')]
#[ORM\Index(columns: ['exited_at'], name: 'idx_student_exit_exited')]
class StudentExit
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private AcademicYear $academicYear;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Student $student;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Group $group;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ExitReason $reason;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observations = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $exitedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Teacher $authorizedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $returnedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Teacher $returnedBy = null;

    /**
     * Instante en que la tarea nocturna dio por cerrada una salida de un día anterior sin regreso
     * registrado. No es un regreso (`returnedAt` sigue vacío, así que no cuenta para los tiempos);
     * solo evita que el estudiante figure fuera para siempre.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $autoClosedAt = null;

    /** Cuándo se avisó por push, una sola vez, de que el estudiante lleva mucho rato fuera (ver NotifyLongExitsHandler). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $longNoticeSentAt = null;

    public function __construct()
    {
        $this->exitedAt = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): static
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getStudent(): Student
    {
        return $this->student;
    }

    public function setStudent(Student $student): static
    {
        $this->student = $student;

        return $this;
    }

    public function getGroup(): Group
    {
        return $this->group;
    }

    public function setGroup(Group $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function getReason(): ExitReason
    {
        return $this->reason;
    }

    public function setReason(ExitReason $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function setObservations(?string $observations): static
    {
        $this->observations = $observations;

        return $this;
    }

    public function getExitedAt(): \DateTimeImmutable
    {
        return $this->exitedAt;
    }

    public function setExitedAt(\DateTimeImmutable $exitedAt): static
    {
        $this->exitedAt = $exitedAt;

        return $this;
    }

    public function getAuthorizedBy(): Teacher
    {
        return $this->authorizedBy;
    }

    public function setAuthorizedBy(Teacher $authorizedBy): static
    {
        $this->authorizedBy = $authorizedBy;

        return $this;
    }

    public function getReturnedAt(): ?\DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function getReturnedBy(): ?Teacher
    {
        return $this->returnedBy;
    }

    public function isReturned(): bool
    {
        return $this->returnedAt !== null;
    }

    public function markReturned(Teacher $by, ?\DateTimeImmutable $at = null): static
    {
        $this->returnedAt = $at ?? now();
        $this->returnedBy = $by;

        return $this;
    }

    public function getAutoClosedAt(): ?\DateTimeImmutable
    {
        return $this->autoClosedAt;
    }

    public function isAutoClosed(): bool
    {
        return $this->autoClosedAt !== null;
    }

    /** El estudiante sigue figurando fuera: ni ha regresado ni se ha cerrado automáticamente. */
    public function isOut(): bool
    {
        return $this->returnedAt === null && $this->autoClosedAt === null;
    }

    public function getLongNoticeSentAt(): ?\DateTimeImmutable
    {
        return $this->longNoticeSentAt;
    }

    public function markLongNoticeSent(?\DateTimeImmutable $at = null): static
    {
        $this->longNoticeSentAt = $at ?? now();

        return $this;
    }

    public function markAutoClosed(?\DateTimeImmutable $at = null): static
    {
        $this->autoClosedAt = $at ?? now();

        return $this;
    }

    /** Segundos fuera: hasta el regreso o, si aún está fuera, hasta `$now`. Nunca negativo. */
    public function secondsOut(\DateTimeImmutable $now): int
    {
        $end = $this->returnedAt ?? $now;

        return max(0, $end->getTimestamp() - $this->exitedAt->getTimestamp());
    }
}
