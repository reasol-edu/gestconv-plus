<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Las copias de seguridad guardadas en el servidor (`APP_BACKUP_DIR`, por defecto `var/backups`): listarlas, guardar una nueva,
 * borrarlas y localizarlas. Quien pide una copia lo hace **por su nombre**, que debe coincidir exactamente con un fichero de la
 * carpeta: nunca se construye una ruta a partir de lo que llega de la web.
 */
class BackupLibrary
{
    /** Nombres admitidos: solo letras, cifras, punto, guion y guion bajo, y la extensión .zip. */
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/';

    /** Más copias que estas no se listan (la pantalla no es un explorador de ficheros). */
    private const int MAX_LISTED = 50;

    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly DatabaseRestore $restore,
        private readonly ClockInterface $clock,
        #[Autowire('%app.backup_dir%')]
        private readonly string $dir,
    ) {}

    public function directory(): string
    {
        return $this->dir;
    }

    /** @return list<StoredBackup> las más recientes primero */
    public function list(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            if (preg_match(self::NAME_PATTERN, $name) === 1 && is_file($this->dir . '/' . $name)) {
                $files[$name] = (int) filemtime($this->dir . '/' . $name);
            }
        }
        arsort($files);

        $stored = [];
        foreach (array_slice(array_keys($files), 0, self::MAX_LISTED) as $name) {
            $stored[] = $this->describe($name, $files[$name]);
        }

        return $stored;
    }

    /** La copia con ese nombre exacto, si está en la carpeta. */
    public function find(string $name): ?StoredBackup
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || !$this->isInside($name)) {
            return null;
        }

        return $this->describe($name, (int) filemtime($this->dir . '/' . $name));
    }

    /** Ruta completa de una copia ya localizada con find()/list(). */
    public function path(StoredBackup $backup): string
    {
        return $this->dir . '/' . $backup->name;
    }

    /** Guarda en el servidor una copia de los datos actuales. */
    public function save(string $prefix = 'gestconv-copia'): StoredBackup
    {
        $this->ensureDir();

        $name = sprintf('%s-%s.zip', $prefix, $this->clock->now()->format('Ymd-His'));
        $this->backup->create($this->dir . '/' . $name);

        return $this->find($name) ?? throw new BackupException('La copia se creó pero no se encuentra en la carpeta de copias.');
    }

    public function delete(string $name): bool
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || !$this->isInside($name)) {
            return false;
        }

        return @unlink($this->dir . '/' . $name);
    }

    /**
     * Si el docente con ese nombre de usuario figura en la copia (la restauración sustituye todas las cuentas: quien restaura debe
     * saber con qué credenciales volverá a entrar). Null si no se puede comprobar.
     */
    public function containsUser(StoredBackup $backup, string $username): ?bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->path($backup)) !== true) {
            return null;
        }

        try {
            $stream = $zip->getStream('data/teacher.ndjson');
            if ($stream === false) {
                return null;
            }
            while (($line = fgets($stream)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row) && ($row['username'] ?? null) === $username) {
                    fclose($stream);

                    return true;
                }
            }
            fclose($stream);

            return false;
        } finally {
            $zip->close();
        }
    }

    private function describe(string $name, int $modifiedAt): StoredBackup
    {
        $path = $this->dir . '/' . $name;
        try {
            $manifest = $this->restore->readManifest($path);
            $problems = $this->restore->problems($manifest);
        } catch (BackupException $e) {
            return new StoredBackup($name, (int) filesize($path), $modifiedAt, null, [$e->getMessage()]);
        }

        return new StoredBackup($name, (int) filesize($path), $modifiedAt, $manifest, $problems);
    }

    /** El fichero existe y, una vez resueltos los enlaces simbólicos, está dentro de la carpeta de copias. */
    private function isInside(string $name): bool
    {
        $real = realpath($this->dir . '/' . $name);
        $base = realpath($this->dir);

        return $real !== false && $base !== false && is_file($real) && str_starts_with($real, $base . DIRECTORY_SEPARATOR);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new BackupException(sprintf('No se pudo crear la carpeta de copias «%s».', $this->dir));
        }
    }
}
