<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000051 extends AbstractMigration
{
    private const array FEATURES = [
        'features.exits'           => ['4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f01', 10],
        'features.absences'        => ['4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f02', 20],
        'features.daily_notes'     => ['4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f03', 30],
        'features.sanction_tasks'  => ['4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f04', 40],
    ];

    public function getDescription(): string
    {
        return 'Ajustes features.* para activar o desactivar Salidas, Ausencias, Notas y tareas de sanción, a nivel global y de centro (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // El id va como parámetro con los 16 bytes del UUID, que es como lo envía Doctrine en SQLite (ver la migración 050).
        foreach (self::FEATURES as $key => [$id, $position]) {
            $this->addSql(
                "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (?, ?, 'boolean', 'true', 1, 1, 0, NULL, NULL, NULL, 'settings.category.features', 6, ?)",
                [Uuid::fromString($id)->toBinary(), $key, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $keys = "'" . implode("', '", array_keys(self::FEATURES)) . "'";
        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key IN ($keys))");
        $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key IN ($keys))");
        $this->addSql("DELETE FROM setting_definition WHERE key IN ($keys)");
    }
}
