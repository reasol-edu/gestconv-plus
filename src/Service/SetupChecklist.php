<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;

/** Estado de la lista de inicio de curso de un centro: el curso activo y sus pasos. */
final readonly class SetupChecklist
{
    /** @param list<SetupChecklistItem> $items */
    public function __construct(
        public ?AcademicYear $year,
        public array $items,
    ) {}

    public function requiredTotal(): int
    {
        return count(array_filter($this->items, static fn (SetupChecklistItem $i): bool => $i->required));
    }

    public function requiredDone(): int
    {
        return count(array_filter($this->items, static fn (SetupChecklistItem $i): bool => $i->required && $i->done));
    }

    /** Todo lo imprescindible hecho: el curso está preparado (los pasos opcionales pueden seguir pendientes). */
    public function isComplete(): bool
    {
        return $this->requiredDone() === $this->requiredTotal();
    }

    /** Porcentaje (0-100) de pasos imprescindibles hechos. */
    public function percent(): int
    {
        $total = $this->requiredTotal();

        return $total === 0 ? 100 : (int) floor($this->requiredDone() / $total * 100);
    }

    /** @return list<SetupChecklistItem> */
    public function pendingRequired(): array
    {
        return array_values(array_filter($this->items, static fn (SetupChecklistItem $i): bool => $i->required && !$i->done));
    }
}
