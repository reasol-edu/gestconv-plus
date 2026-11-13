<?php

declare(strict_types=1);

namespace App\Service\Backup;

/** Lanza una restauración en un proceso aparte, para que no dependa de la petición web que la pidió. */
interface RestoreLauncher
{
    /** Si este servidor permite lanzar procesos (en hostings compartidos suele estar desactivado). */
    public function isAvailable(): bool;

    /**
     * Lanza `app:backup:restore` sobre la copia y vuelve enseguida. Quien llama ya ha tomado el bloqueo: el proceso hijo lo
     * hereda (`--lock-held`) y lo libera al terminar.
     *
     * @throws BackupException si el proceso no se pudo lanzar
     */
    public function launch(string $zipPath, string $by): void;

    /** La orden de consola equivalente, para mostrarla cuando no se puede lanzar desde la web. */
    public function consoleCommand(string $zipPath): string;
}
