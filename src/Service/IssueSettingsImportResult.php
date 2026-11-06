<?php

declare(strict_types=1);

namespace App\Service;

/** Resumen de una importación de la configuración de incidencias. */
final readonly class IssueSettingsImportResult
{
    /**
     * @param list<string> $unresolved responsables del fichero que no existen en el curso activo («usuario (nombre)»)
     */
    public function __construct(
        public int $typesCreated,
        public int $typesUpdated,
        public int $typesIgnored,
        public int $prioritiesCreated,
        public int $prioritiesUpdated,
        public int $prioritiesIgnored,
        public array $unresolved,
    ) {}
}
