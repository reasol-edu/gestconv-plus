<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normaliza los ids de las filas sembradas por migraciones al formato que usa Doctrine en SQLite (SQLite)';
    }

    /**
     * En SQLite, Doctrine guarda y compara el tipo uuid como un TEXTO que contiene los 16 bytes del UUID
     * (lo envía como cadena, no como blob), de modo que un id solo coincide con él mismo en ese formato.
     * Varias migraciones antiguas sembraron filas de otra forma: con el UUID escrito como texto de 36
     * caracteres (el catálogo de ajustes, setting_definition, y, en las bases que ya tenían centros cuando
     * se ejecutaron, los métodos de comunicación, las ubicaciones, los tipos de nota y las asignaciones de
     * docentes a grupos, group_teacher) o como blob (los motivos de salida que la 048 sembró en los
     * centros existentes). Esas filas se listan, pero no se pueden localizar por su id ni ninguna clave
     * foránea puede apuntar a ellas: guardar un ajuste, editar o borrar uno de esos elementos, registrar
     * una tarea de sanción o una salida con uno de esos motivos fallaba.
     *
     * Aquí cada id en cualquiera de los dos formatos erróneos pasa a ser el mismo UUID en el formato de
     * Doctrine, y también cada referencia a él en las demás tablas (las claves foráneas se leen del
     * propio esquema). No se tocan las migraciones antiguas porque ya se ejecutaron en todas las
     * versiones publicadas: este paso las repara, y en una base sin ids erróneos (la mayoría) no hace nada.
     */
    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'");

        // tabla padre => columnas de otras tablas que apuntan a su id
        $references = [];
        foreach ($tables as $table) {
            foreach ($this->connection->fetchAllAssociative(sprintf('PRAGMA foreign_key_list("%s")', $table)) as $foreignKey) {
                if ($foreignKey['to'] === null || $foreignKey['to'] === 'id') {
                    $references[$foreignKey['table']][] = [$table, $foreignKey['from']];
                }
            }
        }

        $pragmaDone = false;
        foreach ($tables as $table) {
            if (!$this->hasUuidIdColumn($table)) {
                continue;
            }

            // Los ids correctos son un texto de 16 bytes: length() cuenta hasta el primer byte nulo, así que
            // nunca vale 36. Los erróneos son un blob, o un texto de 36 caracteres.
            $rows = $this->connection->fetchAllAssociative(sprintf(
                "SELECT typeof(id) AS kind, id FROM \"%s\" WHERE typeof(id) = 'blob' OR (typeof(id) = 'text' AND length(id) = 36)",
                $table
            ));
            foreach ($rows as $row) {
                $id = (string) $row['id'];
                if ($row['kind'] === 'blob') {
                    if (strlen($id) !== 16) {
                        continue;
                    }
                    $old    = "X'" . bin2hex($id) . "'";
                    $binary = $id;
                } else {
                    if (!Uuid::isValid($id)) {
                        continue;
                    }
                    $old    = "'" . $id . "'";
                    $binary = Uuid::fromString($id)->toBinary();
                }

                if (!$pragmaDone) {
                    // El padre y sus hijos cambian en la misma transacción: las claves se comprueban al confirmar, no fila a fila.
                    $this->addSql('PRAGMA defer_foreign_keys = ON');
                    $pragmaDone = true;
                }

                $this->addSql(sprintf('UPDATE "%s" SET id = ? WHERE id = %s', $table, $old), [$binary]);
                foreach ($references[$table] ?? [] as [$childTable, $childColumn]) {
                    $this->addSql(sprintf('UPDATE "%s" SET "%s" = ? WHERE "%s" = %s', $childTable, $childColumn, $childColumn, $old), [$binary]);
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // Nada que deshacer: los ids en el formato de Doctrine son los correctos, y los otros nunca funcionaron del todo.
    }

    private function hasUuidIdColumn(string $table): bool
    {
        foreach ($this->connection->fetchAllAssociative(sprintf('PRAGMA table_info("%s")', $table)) as $column) {
            if ($column['name'] === 'id') {
                return str_starts_with(strtoupper((string) $column['type']), 'CHAR(36)');
            }
        }

        return false;
    }
}
