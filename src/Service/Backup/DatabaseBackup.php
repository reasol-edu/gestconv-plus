<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
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
    /** Filas por página en las tablas normales y en las que guardan ficheros (de una en una: un fichero puede pesar decenas de MB). */
    private const int BATCH      = 200;
    private const int FILE_BATCH = 1;

    private const int MEMORY_LIMIT_BYTES = 512 * 1024 * 1024;

    /** @var list<string>|null */
    private ?array $fileTables = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly DatabaseTables $tables,
        private readonly ClockInterface $clock,
        #[Autowire('%app.version%')]
        private readonly string $appVersion,
    ) {}

    public function create(string $zipPath): BackupManifest
    {
        $this->raiseMemoryLimit();

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
                foreach ($this->rows($table) as $row) {
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

    /**
     * pdo_mysql y pdo_pgsql cargan en memoria el resultado completo antes de devolver la primera fila, así que un
     * `SELECT *` sobre la tabla de ficheros los tendría todos a la vez. Por eso se lee por páginas de la clave primaria
     * (`WHERE pk > ? ORDER BY pk LIMIT n`, que además no se degrada como OFFSET): de una en una en las tablas con
     * ficheros y de 200 en 200 en las demás. SQLite recorre el resultado de forma perezosa y no lo necesita; las tablas
     * de unión (clave compuesta) son pequeñas y se recorren tal cual.
     *
     * @return iterable<array<string, mixed>>
     */
    private function rows(string $table): iterable
    {
        $quoted = $this->tables->quote($table);
        $key    = $this->tables->primaryKey($table);

        if ($this->tables->platform() === 'sqlite' || count($key) !== 1) {
            yield from $this->connection->iterateAssociative('SELECT * FROM ' . $quoted . ($key === [] ? '' : ' ORDER BY ' . implode(', ', array_map($this->tables->quote(...), $key))));

            return;
        }

        $pk        = $key[0];
        $quotedPk  = $this->tables->quote($pk);
        $batch     = in_array($table, $this->fileTables(), true) ? self::FILE_BATCH : self::BATCH;
        $binaryKey = $this->tables->columns($table)[$pk] ?? false;
        $last      = null;

        while (true) {
            $page = $last === null
                ? $this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY %s LIMIT %d', $quoted, $quotedPk, $batch))
                : $this->connection->fetchAllAssociative(
                    sprintf('SELECT * FROM %s WHERE %s > ? ORDER BY %s LIMIT %d', $quoted, $quotedPk, $quotedPk, $batch),
                    [$last],
                    [$binaryKey ? ParameterType::BINARY : (is_int($last) ? ParameterType::INTEGER : ParameterType::STRING)],
                );

            if ($page === []) {
                return;
            }

            yield from $page;

            $next = end($page)[$pk] ?? null;
            if (is_resource($next)) {
                $next = stream_get_contents($next);
            }
            if ($next === null || $next === $last || count($page) < $batch) {
                return; // sin clave que seguir (o que no avanza): se acabó, sin riesgo de bucle infinito
            }
            $last = $next;
        }
    }

    /**
     * Las tablas con ficheros (columnas BLOB), según las entidades: no según el tipo de columna en la base de datos,
     * porque en SQLite los uuid también se declaran BLOB.
     *
     * @return list<string>
     */
    private function fileTables(): array
    {
        if ($this->fileTables !== null) {
            return $this->fileTables;
        }

        $tables = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->getFieldNames() as $field) {
                if ($metadata->getTypeOfField($field) === 'blob') {
                    $tables[] = $metadata->getTableName();
                    break;
                }
            }
        }

        return $this->fileTables = $tables;
    }

    /** Una copia con ficheros necesita más de los 128 MB habituales de PHP: se sube a 512 MB si el límite es menor. */
    private function raiseMemoryLimit(): void
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return;
        }

        $bytes = (int) $limit;
        $bytes *= match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($bytes < self::MEMORY_LIMIT_BYTES) {
            ini_set('memory_limit', '512M');
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
