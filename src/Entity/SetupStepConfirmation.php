<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SetupStepConfirmationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * Paso de la lista de inicio de curso que el equipo directivo ha dado por revisado a mano (los que no se pueden
 * detectar a partir de los datos). Cuelga del curso académico: al abrir un curso nuevo la lista empieza limpia, y se
 * borra con el curso.
 */
#[ORM\Entity(repositoryClass: SetupStepConfirmationRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_setup_step_confirmation', columns: ['academic_year_id', 'step_key'])]
class SetupStepConfirmation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    #[ORM\Column(length: 40)]
    private string $stepKey;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Teacher $confirmedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $confirmedAt;

    public function __construct(AcademicYear $academicYear, string $stepKey, ?Teacher $confirmedBy)
    {
        $this->academicYear = $academicYear;
        $this->stepKey      = $stepKey;
        $this->confirmedBy  = $confirmedBy;
        $this->confirmedAt  = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function getStepKey(): string
    {
        return $this->stepKey;
    }

    public function getConfirmedBy(): ?Teacher
    {
        return $this->confirmedBy;
    }

    public function getConfirmedAt(): \DateTimeImmutable
    {
        return $this->confirmedAt;
    }
}
