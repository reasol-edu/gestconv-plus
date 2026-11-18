<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000086 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajuste security.idle_timeout_minutes (cierre de sesión por inactividad), global, de centro y de docente (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // El id va como parámetro con los 16 bytes del UUID, que es como los envía Doctrine en SQLite (ver la migración 050).
        $this->addSql(
            "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES (?, 'security.idle_timeout_minutes', 'integer', '120', 1, 1, 1, 0, 1440, NULL, 'settings.category.security', 8, 10)",
            [Uuid::v4()->toBinary()]
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $definition = "(SELECT id FROM setting_definition WHERE key = 'security.idle_timeout_minutes')";
        $this->addSql("DELETE FROM teacher_setting_value WHERE definition_id IN $definition");
        $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN $definition");
        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN $definition");
        $this->addSql("DELETE FROM setting_definition WHERE key = 'security.idle_timeout_minutes'");
    }
}
