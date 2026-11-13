<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Restaura una copia hecha con DatabaseBackup **sustituyendo todos los datos** de la base de datos actual. Solo es posible
 * en la misma plataforma (SQLite, PostgreSQL o MySQL) y con el esquema en la misma versión de migración que la copia: si no,
 * se rechaza antes de tocar nada (hay que migrar o actualizar la aplicación primero).
 *
 * Las claves foráneas se difieren o se desactivan mientras dura la restauración (hay ciclos entre tablas, p. ej. el centro
 * y su curso activo) y se comprueban al terminar, así que una copia incoherente falla sin dejar datos a medias: todo va en
 * una transacción.
 */
final class DatabaseRestore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseTables $tables,
    ) {}

    /** Lee y valida el manifiesto de un .zip sin tocar la base de datos. */
    public function readManifest(string $zipPath): BackupManifest
    {
        $zip = $this->open($zipPath);
        try {
            $raw = $zip->getFromName('manifest.json');
            if ($raw === false) {
                throw new BackupException('El fichero no es una copia de seguridad de GestConv+ (falta manifest.json).');
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new BackupException('El manifiesto de la copia de seguridad no se puede leer.');
            }

            return BackupManifest::fromArray($data);
        } finally {
            $zip->close();
        }
    }

    /**
     * Comprueba que la copia se puede restaurar en esta base de datos y devuelve los motivos por los que no (vacío = se puede).
     *
     * @return list<string>
     */
    public function problems(BackupManifest $manifest): array
    {
        $problems = [];

        if ($manifest->format !== BackupManifest::FORMAT) {
            $problems[] = sprintf('El formato de la copia (%d) no es el que entiende esta versión (%d).', $manifest->format, BackupManifest::FORMAT);
        }
        if ($manifest->platform !== $this->tables->platform()) {
            $problems[] = sprintf('La copia es de %s y esta base de datos es %s: solo se puede restaurar en la misma plataforma.', $manifest->platform, $this->tables->platform());
        }
        if ($manifest->schemaVersion !== $this->tables->schemaVersion()) {
            $problems[] = sprintf(
                'La copia es del esquema «%s» y esta base de datos está en «%s»: migra la base de datos (o instala la versión de la aplicación de la copia, la %s) y repite la restauración.',
                $manifest->schemaVersion ?? '—',
                $this->tables->schemaVersion() ?? '—',
                $manifest->appVersion,
            );
        }
        if ($problems !== []) {
            return $problems;
        }

        $present = $this->tables->names();
        foreach (array_diff($present, array_keys($manifest->tables)) as $missing) {
            $problems[] = sprintf('La copia no incluye la tabla «%s».', $missing);
        }
        foreach ($manifest->tables as $table => $info) {
            if (!in_array($table, $present, true)) {
                $problems[] = sprintf('La copia lleva la tabla «%s», que no existe en esta base de datos.', $table);
                continue;
            }
            $columns = array_keys($this->tables->columns($table));
            if (array_diff($columns, $info['columns']) !== [] || array_diff($info['columns'], $columns) !== []) {
                $problems[] = sprintf('Las columnas de la tabla «%s» no coinciden con las de la copia.', $table);
            }
        }

        return $problems;
    }

    /**
     * @param callable(string $table, int $rows): void|null $progress
     *
     * @return array<string, int> filas restauradas por tabla
     */
    public function restore(string $zipPath, ?callable $progress = null): array
    {
        $manifest = $this->readManifest($zipPath);
        $problems = $this->problems($manifest);
        if ($problems !== []) {
            throw new BackupException(implode("\n", $problems));
        }

        $zip      = $this->open($zipPath);
        $platform = $this->tables->platform();
        $restored = [];

        try {
            $this->beforeRestore($platform);
            try {
                $this->connection->beginTransaction();
                if ($platform === 'postgresql') {
                    $this->connection->executeStatement('SET CONSTRAINTS ALL DEFERRED');
                }

                // Vacía primero todas las tablas: las migraciones siembran filas con ids propios (p. ej. las definiciones de
                // ajustes) y, si se quedaran, chocarían con las de la copia.
                foreach (array_keys($manifest->tables) as $table) {
                    $this->connection->executeStatement('DELETE FROM ' . $this->tables->quote($table));
                }

                foreach ($manifest->tables as $table => $info) {
                    $restored[$table] = $this->insertTable($zip, $table, $info['columns']);
                    if ($progress !== null) {
                        $progress($table, $restored[$table]);
                    }
                }

                if ($platform === 'postgresql') {
                    $this->resetSequences(array_keys(array_filter($manifest->tables, static fn (array $info): bool => in_array('id', $info['columns'], true))));
                }

                $this->connection->commit();
            } catch (\Throwable $e) {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
                throw $e instanceof BackupException ? $e : new BackupException('La restauración ha fallado y no se ha cambiado nada: ' . $e->getMessage(), 0, $e);
            } finally {
                $this->afterRestore($platform);
            }
        } finally {
            $zip->close();
        }

        return $restored;
    }

    private function open(string $zipPath): \ZipArchive
    {
        if (!is_file($zipPath)) {
            throw new BackupException(sprintf('No existe el fichero «%s».', $zipPath));
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new BackupException('El fichero no es un .zip válido.');
        }

        return $zip;
    }

    /** @param list<string> $columns */
    private function insertTable(\ZipArchive $zip, string $table, array $columns): int
    {
        $stream = $zip->getStream('data/' . $table . '.ndjson');
        if ($stream === false) {
            throw new BackupException(sprintf('Falta data/%s.ndjson en la copia.', $table));
        }

        // En SQLite todo se envía como texto, que es como Doctrine envía los ids (un texto con los 16 bytes del UUID, aunque la
        // columna se declare BLOB): si se enviaran como BLOB no coincidirían en las búsquedas. Los binarios (adjuntos) se leen igual.
        $lobAllowed = $this->tables->platform() !== 'sqlite';
        $binary     = array_map(static fn (bool $isBinary): bool => $isBinary && $lobAllowed, $this->tables->columns($table));
        $sql        = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->tables->quote($table),
            implode(', ', array_map($this->tables->quote(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );

        $count = 0;
        try {
            while (($line = fgets($stream)) !== false) {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($row)) {
                    throw new BackupException(sprintf('Una fila de «%s» no se puede leer.', $table));
                }

                $values = $types = [];
                foreach ($columns as $column) {
                    [$value, $type] = $this->decode($row[$column] ?? null, $binary[$column] ?? false);
                    $values[] = $value;
                    $types[]  = $type;
                }

                $this->connection->executeStatement($sql, $values, $types);
                ++$count;
            }
        } finally {
            fclose($stream);
        }

        return $count;
    }

    /** @return array{0: mixed, 1: ParameterType} */
    private function decode(mixed $value, bool $binaryColumn): array
    {
        if (is_array($value) && isset($value['$b64'])) {
            $bytes = is_string($value['$b64']) ? base64_decode($value['$b64'], true) : false;
            if ($bytes === false) {
                throw new BackupException('Un valor binario de la copia está dañado.');
            }

            return [$bytes, $binaryColumn ? ParameterType::LARGE_OBJECT : ParameterType::STRING];
        }

        return match (true) {
            $value === null => [null, ParameterType::NULL],
            is_bool($value) => [$value, ParameterType::BOOLEAN],
            is_int($value)  => [$value, ParameterType::INTEGER],
            default         => [$value, $binaryColumn ? ParameterType::LARGE_OBJECT : ParameterType::STRING],
        };
    }

    private function beforeRestore(string $platform): void
    {
        switch ($platform) {
            case 'mysql':
                $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
                break;
            case 'sqlite':
                // Fuera de una transacción, o no tiene efecto.
                $this->connection->executeStatement('PRAGMA foreign_keys = OFF');
                break;
            case 'postgresql':
                // Sin ser superusuario no se pueden desactivar los triggers de las claves foráneas, pero el propietario de las
                // tablas sí puede hacerlas diferibles: se comprueban al final de la transacción.
                foreach ($this->foreignKeys() as [$table, $constraint]) {
                    $this->connection->executeStatement(sprintf('ALTER TABLE %s ALTER CONSTRAINT %s DEFERRABLE INITIALLY IMMEDIATE', $table, $constraint));
                }
                break;
        }
    }

    private function afterRestore(string $platform): void
    {
        switch ($platform) {
            case 'mysql':
                $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                break;
            case 'sqlite':
                $this->connection->executeStatement('PRAGMA foreign_keys = ON');
                break;
            case 'postgresql':
                foreach ($this->foreignKeys() as [$table, $constraint]) {
                    $this->connection->executeStatement(sprintf('ALTER TABLE %s ALTER CONSTRAINT %s NOT DEFERRABLE', $table, $constraint));
                }
                break;
        }
    }

    /** @return list<array{0: string, 1: string}> tabla (ya entrecomillada) y nombre (entrecomillado) de cada clave foránea */
    private function foreignKeys(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.conrelid::regclass::text AS tbl, quote_ident(c.conname) AS name
               FROM pg_constraint c
               JOIN pg_namespace n ON n.oid = c.connamespace
              WHERE c.contype = 'f' AND n.nspname = current_schema()"
        );

        $keys = [];
        foreach ($rows as $row) {
            if (is_string($row['tbl']) && is_string($row['name'])) {
                $keys[] = [$row['tbl'], $row['name']];
            }
        }

        return $keys;
    }

    /**
     * Tras insertar ids explícitos, las secuencias de PostgreSQL (activity_log.id…) siguen en 1: se colocan tras el mayor id.
     *
     * @param list<string> $tables las tablas que tienen una columna `id`
     */
    private function resetSequences(array $tables): void
    {
        foreach ($tables as $table) {
            $quoted   = $this->tables->quote($table);
            $sequence = $this->connection->fetchOne('SELECT pg_get_serial_sequence(?, ?)', [$quoted, 'id']);
            if (!is_string($sequence)) {
                continue;
            }

            $this->connection->executeStatement(
                sprintf('SELECT setval(?, COALESCE((SELECT MAX(id) FROM %1$s), 1), (SELECT MAX(id) IS NOT NULL FROM %1$s))', $quoted),
                [$sequence],
            );
        }
    }
}
