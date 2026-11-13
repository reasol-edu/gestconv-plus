<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000079 extends AbstractMigration
{
    private const string KEY = 'display.draft_retention_hours';

    public function getDescription(): string
    {
        return 'Ajuste global «Caducidad de los borradores de partes» (horas; 0 = sin borradores) (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // El id va como parámetro con los 16 bytes del UUID, que es como los envía Doctrine en SQLite (ver la migración 050).
        $this->addSql(
            'INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES (?, ?, ?, ?, 1, 0, 0, 0, 168, NULL, ?, 10, 30)',
            [Uuid::v4()->toBinary(), self::KEY, 'integer', '24', 'settings.category.display']
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $key = self::KEY;
        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM $table WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key = '$key'");
    }
}
