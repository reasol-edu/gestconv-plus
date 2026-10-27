<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;
use App\Entity\Student;

/**
 * Los estudiantes de una actividad que pertenecen a uno de sus grupos, para el informe PDF.
 */
final readonly class SchoolEventRoster
{
    /**
     * @param list<Student> $students ordenados por apellidos y nombre
     */
    public function __construct(
        public Group $group,
        public array $students,
    ) {}

    public function count(): int
    {
        return \count($this->students);
    }
}
