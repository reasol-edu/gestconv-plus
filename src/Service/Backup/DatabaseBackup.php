<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Copia de seguridad portable de toda la base de datos: un .zip con `manifest.json` y, por cada tabla, un fichero NDJSON
 * (una fila por línea) en `data/`. No depende de pg_dump ni de mysqldump y es igual en SQLite, PostgreSQL y MySQL; solo se
 * puede restaurar en la misma plataforma y con las mismas migraciones (ver DatabaseRestore). Los adjuntos y las plantillas
 * PDF viven en la base de datos, así que van incluidos.
 *
 * Las filas se leen dentro de una única transacción con instantánea, para que la copia sea coherente aunque se use la
 * aplicación mientras se genera, y se escriben a ficheros temporales (nunca se cargan tablas enteras en memoria).
 */
final class DatabaseBackup
{
    /** Filas por página en las tablas con binarios (adjuntos): evita cargar de golpe todos los ficheros del cliente. */
    private const int PAGE_SIZE = 50;

    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseTables $tables,
        private readonly ClockInterface $clock,
        #[Autowire('%app.version%')]
        private readonly string $appVersion,
    ) {}

    public function create(string $zipPath): BackupManifest
    {
        $tmp = sys_get_temp_dir() . '/gestconv-backup-' . bin2hex(random_bytes(6));
        if (!mkdir($tmp, 0700) && !is_dir($tmp)) {
            throw new BackupException('No se pudo crear la carpeta temporal de la copia de seguridad.');
        }

        try {
            $info = $this->dumpTables($tmp);

            $manifest = new BackupManifest(
                BackupManifest::FORMAT,
                $this->appVersion,
                $this->tables->schemaVersion(),
                $this->tables->platform(),
                $this->clock->now()->format(\DATE_ATOM),
                $info,
                DatabaseTables::EXCLUDED,
            );

            $this->zip($zipPath, $manifest, $tmp);

            return $manifest;
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmp);
        }
    }

    /** @return array<string, array{rows: int, columns: list<string>}> */
    private function dumpTables(string $dir): array
    {
        $info = [];

        $this->connection->beginTransaction();
        try {
            if ($this->tables->platform() === 'postgresql') {
                $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            foreach ($this->tables->names() as $table) {
                $columns = $this->tables->columns($table);
                $handle  = fopen($dir . '/' . $table . '.ndjson', 'wb');
                if ($handle === false) {
                    throw new BackupException(sprintf('No se pudo escribir la tabla «%s» en la carpeta temporal.', $table));
                }

                $rows = 0;
                foreach ($this->rows($table, in_array(true, $columns, true)) as $row) {
                    $encoded = [];
                    foreach ($row as $name => $value) {
                        $encoded[$name] = $this->encode($value, $columns[$name] ?? false);
                    }
                    fwrite($handle, json_encode($encoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                    ++$rows;
                }
                fclose($handle);

                $info[$table] = ['rows' => $rows, 'columns' => array_keys($columns)];
            }
        } finally {
            // Solo se ha leído: se cierra la transacción sin más.
            $this->connection->rollBack();
        }

        return $info;
    }

    /** @return iterable<array<string, mixed>> */
    private function rows(string $table, bool $hasBinaries): iterable
    {
        $quoted = $this->tables->quote($table);

        if (!$hasBinaries) {
            yield from $this->connection->iterateAssociative('SELECT * FROM ' . $quoted);

            return;
        }

        $order = implode(', ', array_map($this->tables->quote(...), $this->tables->primaryKey($table) ?: array_keys($this->tables->columns($table))));
        for ($offset = 0;; $offset += self::PAGE_SIZE) {
            $page = $this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY %s LIMIT %d OFFSET %d', $quoted, $order, self::PAGE_SIZE, $offset));
            yield from $page;
            if (count($page) < self::PAGE_SIZE) {
                return;
            }
        }
    }

    /**
     * Los binarios, y cualquier texto que no sea UTF-8 válido (los ids de SQLite son 16 bytes en bruto), van en base64 dentro
     * de `{"$b64": …}`; lo demás, tal cual.
     */
    private function encode(mixed $value, bool $binaryColumn): mixed
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        $value = is_scalar($value) || $value instanceof \Stringable ? (string) $value : throw new BackupException('Un valor de la base de datos no se puede copiar (tipo no admitido).');

        return ($binaryColumn || !mb_check_encoding($value, 'UTF-8')) ? ['$b64' => base64_encode($value)] : $value;
    }

    private function zip(string $zipPath, BackupManifest $manifest, string $dir): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('No se pudo crear el fichero de la copia de seguridad.');
        }

        $zip->addFromString('manifest.json', json_encode($manifest->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        foreach (array_keys($manifest->tables) as $table) {
            $zip->addFile($dir . '/' . $table . '.ndjson', 'data/' . $table . '.ndjson');
            $zip->setCompressionName('data/' . $table . '.ndjson', \ZipArchive::CM_DEFLATE);
        }

        if (!$zip->close()) {
            throw new BackupException('No se pudo terminar de escribir la copia de seguridad (¿falta espacio en disco?).');
        }
    }
}
