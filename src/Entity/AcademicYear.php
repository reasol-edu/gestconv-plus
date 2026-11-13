<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AcademicYearRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Clock\now;

#[ORM\Entity(repositoryClass: AcademicYearRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_academic_year_centre', columns: ['name', 'educational_centre_id'])]
class AcademicYear
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 50)]
    private string $name;

    /** Primer día del curso (inclusive). Por defecto, el 15 de septiembre del año con el que empieza el nombre. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    /** Último día del curso (inclusive). Por defecto, el 14 de septiembre del año siguiente. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endDate;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private EducationalCentre $educationalCentre;

    /** @var Collection<int, Teacher> */
    #[ORM\ManyToMany(targetEntity: Teacher::class, inversedBy: 'academicYears', fetch: 'EXTRA_LAZY')]
    #[ORM\JoinTable(name: 'teacher_academic_year')]
    private Collection $teachers;

    public function __construct()
    {
        $this->teachers = new ArrayCollection();
        [$this->startDate, $this->endDate] = self::defaultDates(null);
    }

    /**
     * Fechas por defecto de un curso: del 15 de septiembre del año con el que empieza su nombre («2025-2026» → 2025)
     * al 14 de septiembre del siguiente. Si el nombre no trae ningún año, el curso que contiene el día de hoy.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function defaultDates(?string $name, ?\DateTimeImmutable $today = null): array
    {
        $startYear = null;
        if ($name !== null && preg_match('/(?<!\d)(\d{4})(?!\d)/', $name, $match) === 1) {
            $startYear = (int) $match[1];
        }

        if ($startYear === null) {
            $today ??= now();
            $startYear = (int) $today->format('Y');
            if ($today->format('m-d') < '09-15') {
                --$startYear;
            }
        }

        return [
            new \DateTimeImmutable(sprintf('%04d-09-15', $startYear)),
            new \DateTimeImmutable(sprintf('%04d-09-14', $startYear + 1)),
        ];
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** En un curso nuevo (sin guardar), poner el nombre calcula también sus fechas por defecto; `setDates()` las sustituye. */
    public function setName(string $name): static
    {
        $this->name = $name;
        if (!isset($this->id)) {
            [$this->startDate, $this->endDate] = self::defaultDates($name);
        }

        return $this;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setDates(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate): static
    {
        $this->startDate = $startDate->setTime(0, 0);
        $this->endDate   = $endDate->setTime(0, 0);

        return $this;
    }

    /** Si el día cae dentro del curso (los dos extremos incluidos). */
    public function contains(\DateTimeImmutable $day): bool
    {
        $day = $day->setTime(0, 0);

        return $day >= $this->startDate && $day <= $this->endDate;
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

    /** @return Collection<int, Teacher> */
    public function getTeachers(): Collection
    {
        return $this->teachers;
    }

    public function addTeacher(Teacher $teacher): static
    {
        if (!$this->teachers->contains($teacher)) {
            $this->teachers->add($teacher);
            $teacher->addAcademicYear($this);
        }

        return $this;
    }

    public function removeTeacher(Teacher $teacher): static
    {
        if ($this->teachers->removeElement($teacher)) {
            $teacher->removeAcademicYear($this);
        }

        return $this;
    }
}
