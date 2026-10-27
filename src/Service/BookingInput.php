<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;
use App\Entity\SharedResource;
use App\Entity\Teacher;
use App\Entity\TimeSlot;

/** Lo que el formulario de una reserva envía, ya resuelto contra la base de datos (los ids desconocidos se descartan). */
final readonly class BookingInput
{
    /**
     * @param list<TimeSlot> $slots
     * @param list<Group>    $groups
     * @param array<string, string> $errors campo => clave de traducción (dominio admin)
     */
    public function __construct(
        public ?SharedResource $resource,
        public ?Teacher $teacher,
        public ?\DateTimeImmutable $date,
        public array $slots,
        public array $groups,
        public ?string $description,
        public bool $repeat,
        public ?\DateTimeImmutable $until,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
