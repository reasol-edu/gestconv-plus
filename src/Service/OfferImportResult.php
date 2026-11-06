<?php

declare(strict_types=1);

namespace App\Service;

/** Resumen de una importación de la oferta formativa. */
final readonly class OfferImportResult
{
    /**
     * @param list<string> $unresolved docentes del fichero que no existen en el curso de destino («usuario (nombre)»)
     */
    public function __construct(
        public int $coursesCreated,
        public int $coursesUpdated,
        public int $coursesIgnored,
        public int $groupsCreated,
        public int $groupsUpdated,
        public int $groupsIgnored,
        public int $subjectsAdded,
        public int $subjectsRemoved,
        public int $subjectsKept,
        public array $unresolved,
    ) {}
}
