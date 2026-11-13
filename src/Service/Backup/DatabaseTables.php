<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;

/**
 * Qué tablas y columnas entran en una copia de seguridad y en qué plataforma de base de datos se hace. La lista sale de la
 * propia base de datos (no de las entidades), así que incluye también las tablas de unión sin entidad.
 */
final class DatabaseTables
{
    /**
     * Tablas que se dejan fuera a propósito: la cola de mensajes (restaurarla reenviaría correos y avisos ya enviados) y
     * la propia tabla de versiones de las migraciones (la restauración la comprueba, no la sustituye).
     */
    public const array EXCLUDED = ['messenger_messages', 'doctrine_migration_versions'];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** @return 'sqlite'|'postgresql'|'mysql' */
    public function platform(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform    => 'postgresql',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof SQLitePlatform        => 'sqlite',
            default => throw new BackupException('Plataforma de base de datos no admitida: ' . $platform::class),
        };
    }

    /** @return list<string> las tablas de la copia, por orden alfabético */
    public function names(): array
    {
        // PostgreSQL devuelve ya entrecomillados los nombres que son palabras reservadas («"group"»): se quitan las comillas.
        $names = array_filter(
            array_map(static fn (string $name): string => trim($name, '"`'), $this->connection->createSchemaManager()->listTableNames()),
            static fn (string $name): bool => !in_array($name, self::EXCLUDED, true) && !str_starts_with($name, 'sqlite_'),
        );
        sort($names);

        return $names;
    }

    /**
     * Las columnas de una tabla, con si guardan binarios (BLOB o BINARY): esas se copian siempre en base64.
     *
     * @return array<string, bool> nombre de la columna => es binaria
     */
    public function columns(string $table): array
    {
        $columns = [];
        foreach ($this->connection->createSchemaManager()->listTableColumns($table) as $column) {
            $type = $column->getType();
            $columns[$column->getName()] = $type instanceof BlobType || $type instanceof BinaryType;
        }

        return $columns;
    }

    /** @return list<string> las columnas de la clave primaria, para recorrer la tabla por páginas siempre en el mismo orden */
    public function primaryKey(string $table): array
    {
        $primary = $this->connection->createSchemaManager()->introspectTable($table)->getPrimaryKey();

        return $primary === null ? [] : $primary->getColumns();
    }

    /** La última migración aplicada (la mayor), o null si la base de datos no lleva el registro de migraciones (p. ej. en los tests). */
    public function schemaVersion(): ?string
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['doctrine_migration_versions'])) {
            return null;
        }

        $version = $this->connection->fetchOne('SELECT MAX(version) FROM doctrine_migration_versions');

        return is_string($version) ? $version : null;
    }

    public function quote(string $identifier): string
    {
        return $this->connection->quoteSingleIdentifier($identifier);
    }
}
