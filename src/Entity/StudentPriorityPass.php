<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StudentPriorityPassRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

/**
 * «Pase prioritario» de un estudiante en un curso académico: necesita un trato especial en
 * las salidas del aula. La descripción explica la justificación y se muestra al docente que
 * autoriza la salida.
 */
#[ORM\Entity(repositoryClass: StudentPriorityPassRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_priority_pass_student_year', columns: ['student_id', 'academic_year_id'])]
class StudentPriorityPass
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Student $student;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private AcademicYear $academicYear;

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Teacher $grantedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $grantedAt;

    public function __construct()
    {
        $this->grantedAt = now();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): static
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getGrantedBy(): ?Teacher
    {
        return $this->grantedBy;
    }

    public function setGrantedBy(?Teacher $grantedBy): static
    {
        $this->grantedBy = $grantedBy;

        return $this;
    }

    public function getGrantedAt(): \DateTimeImmutable
    {
        return $this->grantedAt;
    }
}
