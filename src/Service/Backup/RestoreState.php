<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Estado de una restauración en curso o terminada, guardado **en ficheros** (no en la base de datos, que es justo lo que se
 * está sustituyendo): un bloqueo mientras dura, el resultado de la última y la hora en que terminó la última con éxito.
 *
 * - Mientras el bloqueo existe, la aplicación responde 503 (ver RestoreGuardSubscriber). El bloqueo guarda el PID y la hora de
 *   inicio: si el proceso ha muerto o pasan unas horas se considera abandonado, para que un fallo no deje el sitio caído.
 * - Las sesiones creadas antes de la última restauración se cierran: los usuarios de la copia pueden no ser los mismos.
 *
 * Usa `time()` y no el reloj de la aplicación a propósito: se compara con la hora de creación de la sesión, que PHP calcula
 * con `time()`, y esa comparación no debe depender de un reloj simulado.
 */
class RestoreState
{
    /** Un bloqueo más antiguo que esto se da por abandonado (una restauración tarda minutos, no horas). */
    private const int STALE_AFTER = 4 * 3600;

    /** Segundos que se le dan a un proceso recién lanzado para anotar su PID en el bloqueo. */
    private const int NO_PID_AFTER = 60;

    public function __construct(
        #[Autowire('%app.restore_state_dir%')]
        private readonly string $dir,
    ) {}

    /** Hay una restauración en marcha. Es una comprobación de ficheros, sin base de datos: se hace en cada petición. */
    public function isRunning(): bool
    {
        $lock = $this->lockPath();
        if (!is_file($lock)) {
            return false;
        }

        $info = $this->readJson($lock);
        $started = is_int($info['started'] ?? null) ? $info['started'] : (int) @filemtime($lock);
        $pid     = is_int($info['pid'] ?? null) ? $info['pid'] : 0;

        // Sin PID es que el proceso hijo todavía no ha arrancado: si tarda más de un minuto, no arrancó (y no se quedará esperando horas).
        $neverStarted = $pid === 0 && time() - $started > self::NO_PID_AFTER;
        if (time() - $started > self::STALE_AFTER || $neverStarted || ($pid > 0 && !$this->isAlive($pid))) {
            @unlink($lock);

            return false;
        }

        return true;
    }

    /** Toma el bloqueo. False si ya hay una restauración en marcha (otra petición, o la consola). */
    public function acquire(string $by, string $file): bool
    {
        $this->ensureDir();
        if ($this->isRunning()) {
            return false;
        }

        $handle = @fopen($this->lockPath(), 'x');
        if ($handle === false) {
            return false;
        }
        fwrite($handle, json_encode(['started' => time(), 'pid' => 0, 'by' => $by, 'file' => $file], JSON_THROW_ON_ERROR));
        fclose($handle);

        return true;
    }

    /** Anota el proceso que sostiene el bloqueo (el lanzador no conoce el PID del proceso hijo hasta que arranca). */
    public function attachPid(int $pid): void
    {
        $info = $this->readJson($this->lockPath());
        if ($info === []) {
            return;
        }
        $info['pid'] = $pid;
        file_put_contents($this->lockPath(), json_encode($info, JSON_THROW_ON_ERROR));
    }

    public function release(): void
    {
        @unlink($this->lockPath());
    }

    /** Quita un bloqueo atascado a mano (`app:backup:restore --unlock`). Devuelve si había alguno. */
    public function forceUnlock(): bool
    {
        $had = is_file($this->lockPath());
        @unlink($this->lockPath());

        return $had;
    }

    /** Anota que una restauración ha terminado bien: las sesiones anteriores a este instante dejan de valer. */
    public function markRestored(): void
    {
        $this->ensureDir();
        file_put_contents($this->dir . '/restored-at', (string) time());
    }

    public function restoredAt(): ?int
    {
        $path = $this->dir . '/restored-at';
        if (!is_file($path)) {
            return null;
        }
        $value = trim((string) @file_get_contents($path));

        return ctype_digit($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $result */
    public function writeResult(array $result): void
    {
        $this->ensureDir();
        file_put_contents($this->dir . '/last-result.json', json_encode($result + ['at' => time()], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null el resultado de la última restauración (ok, by, file, message…) */
    public function lastResult(): ?array
    {
        $data = $this->readJson($this->dir . '/last-result.json');

        return $data === [] ? null : $data;
    }

    /**
     * Quién y desde cuándo tiene el bloqueo (para el registro), si existe.
     *
     * @return array<string, mixed>
     */
    public function lockInfo(): array
    {
        return $this->readJson($this->lockPath());
    }

    private function lockPath(): string
    {
        return $this->dir . '/restore.lock';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new BackupException(sprintf('No se pudo crear la carpeta de estado «%s».', $this->dir));
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }

        $clean = [];
        foreach ($data as $key => $value) {
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    private function isAlive(int $pid): bool
    {
        // Sin la extensión posix (Windows) no se puede saber: se confía en la antigüedad del bloqueo.
        if (!function_exists('posix_kill')) {
            return true;
        }

        // «No tengo permiso» (EPERM = 1) significa que el proceso existe, aunque sea de otro usuario.
        return @posix_kill($pid, 0) || posix_get_last_error() === 1;
    }
}
