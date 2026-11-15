<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * La restauración falló **después** de reconstruir el esquema: la base de datos ya no está como estaba (puede estar vacía, en
 * una migración intermedia o con datos a medias) y solo se arregla restaurando una copia.
 */
final class SchemaTouchedException extends BackupException {}
