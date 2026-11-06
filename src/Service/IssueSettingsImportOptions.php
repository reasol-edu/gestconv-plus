<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Qué se importa de la configuración de incidencias y qué se hace con lo que ya existe: `$managers` decide si se traen
 * los responsables de los tipos y `$replace`, si lo emparejado se sustituye por los datos del fichero (en vez de ignorarse).
 */
final readonly class IssueSettingsImportOptions
{
    public function __construct(
        public bool $managers = true,
        public bool $replace = false,
    ) {}
}
