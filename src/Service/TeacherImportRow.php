<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Teacher;

/**
 * Una fila válida del CSV de docentes de Séneca, con lo que la importación haría con ella.
 */
final readonly class TeacherImportRow
{
    public const NEW     = 'new';      // el docente no existe: se crea y se añade al curso
    public const ADD     = 'add';      // existe, pero no está en el curso activo: se añade
    public const IN_YEAR = 'in_year';  // ya está en el curso activo

    public const EMAIL_IN_USE  = 'in_use';   // otro docente (o una fila anterior del fichero) ya tiene ese correo
    public const EMAIL_PENDING = 'pending';  // el docente tiene un cambio de correo pendiente de verificar

    public function __construct(
        public string $username,
        public string $firstName,
        public string $lastName,
        /** Correo de la columna «Cuenta Google/Microsoft» si es una dirección válida. */
        public ?string $email,
        public string $status,
        public ?Teacher $teacher,
        /** Motivo por el que el correo del fichero no se puede asignar a este docente, si lo hay. */
        public ?string $emailIssue = null,
    ) {}

    /** ¿Rellenaría la importación el correo (el docente no tiene ninguno, el fichero trae uno válido y nadie más lo usa)? */
    public function fillsEmail(): bool
    {
        return $this->email !== null
            && $this->emailIssue === null
            && ($this->teacher === null || trim((string) $this->teacher->getEmail()) === '');
    }

    /** Sin nada que hacer: ya está en el curso y no hay correo que rellenar. */
    public function isUnchanged(): bool
    {
        return $this->status === self::IN_YEAR && !$this->fillsEmail();
    }

    /** Solo cambiaría el correo (el docente ya estaba en el curso). */
    public function isEmailOnly(): bool
    {
        return $this->status === self::IN_YEAR && $this->fillsEmail();
    }
}
