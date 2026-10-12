<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Teacher;
use App\Repository\TeacherRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Papeles de un docente en un curso (tutor, docente de grupo, guardia, miembro del curso).
 *
 * El menú y varios controladores lo preguntan en cada petición, a menudo varias veces: se resuelve con
 * una sola consulta y se recuerda hasta el final de la petición. La memoria se vacía en cada flush
 * (una asignación nueva cambia la respuesta) y entre peticiones, porque el proceso puede ser de larga vida.
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class TeacherYearRoles implements ResetInterface
{
    /** @var array<string, array{tutors: bool, teaches: bool, guards: bool, member: bool}> */
    private array $memo = [];

    public function __construct(private readonly TeacherRepository $teachers) {}

    public function tutorsAnyGroup(Teacher $teacher, AcademicYear $year): bool
    {
        return $this->roles($teacher, $year)['tutors'];
    }

    /** Da clase o tutoriza algún grupo del curso. */
    public function teachesAnyGroup(Teacher $teacher, AcademicYear $year): bool
    {
        return $this->roles($teacher, $year)['teaches'];
    }

    public function guardsAnyTimeSlot(Teacher $teacher, AcademicYear $year): bool
    {
        return $this->roles($teacher, $year)['guards'];
    }

    public function belongsToYear(Teacher $teacher, AcademicYear $year): bool
    {
        return $this->roles($teacher, $year)['member'];
    }

    public function postFlush(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    /** @return array{tutors: bool, teaches: bool, guards: bool, member: bool} */
    private function roles(Teacher $teacher, AcademicYear $year): array
    {
        $key = $teacher->getId()->toRfc4122() . '|' . $year->getId()->toRfc4122();

        return $this->memo[$key] ??= $this->teachers->findYearRoles($teacher, $year);
    }
}
