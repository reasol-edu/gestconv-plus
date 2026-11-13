<?php

declare(strict_types=1);

namespace App\Service;

/** Un paso de la lista de inicio de curso, ya evaluado. */
final readonly class SetupChecklistItem
{
    /**
     * @param array<string, int|string> $detailParams
     */
    public function __construct(
        public SetupStep $step,
        public bool $done,
        public bool $required,
        /** Sin curso activo no se puede evaluar nada más: el paso queda a la espera. */
        public bool $blocked,
        /** Clave de traducción (dominio `admin`) del dato que lo respalda («Docentes: 42»), o null. */
        public ?string $detailKey = null,
        public array $detailParams = [],
        public ?string $confirmedBy = null,
        public ?\DateTimeImmutable $confirmedAt = null,
    ) {}
}
