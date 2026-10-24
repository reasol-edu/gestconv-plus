<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convierte los ids de texto de setting_definition en UUID binarios, como los guarda Doctrine en SQLite (SQLite)';
    }

    /**
     * El catálogo de ajustes se sembró con los UUID como TEXTO, pero Doctrine guarda y compara el tipo
     * uuid como 16 bytes en SQLite, así que ningún valor guardado podía apuntar a ellos: guardar
     * cualquier ajuste fallaba con un error de clave foránea. Cada id de texto pasa a ser el mismo
     * UUID en binario, y también cada referencia a él en las tres tablas de valores guardados
     * (normalmente ninguna, porque guardar fallaba).
     */
    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn("SELECT id FROM setting_definition WHERE typeof(id) = 'text'");
        if ($ids === []) {
            return;
        }

        // El padre y sus hijos cambian en la misma transacción: las claves se comprueban al confirmar, no fila a fila.
        $this->addSql('PRAGMA defer_foreign_keys = ON');

        foreach ($ids as $id) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
                continue;
            }

            $blob = "X'" . str_replace('-', '', $id) . "'";
            $this->addSql(sprintf("UPDATE setting_definition SET id = %s WHERE id = '%s'", $blob, $id));
            foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
                $this->addSql(sprintf("UPDATE %s SET definition_id = %s WHERE definition_id = '%s'", $table, $blob, $id));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // Nada que deshacer: los ids binarios son los correctos, y los de texto nunca funcionaron.
    }
}
