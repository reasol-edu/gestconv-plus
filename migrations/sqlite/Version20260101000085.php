<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000085 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes globales de las copias de seguridad automáticas: política de conservación y espacio máximo (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // Los ids van como parámetro con los 16 bytes del UUID, que es como los envía Doctrine en SQLite (ver la migración 050).
        $this->addSql(
            "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES (?, 'backup.auto_policy', 'choice', 'off', 1, 0, 0, NULL, NULL, 'off,basic,recommended,extended', 'settings.category.backup', 6, 10)",
            [Uuid::v4()->toBinary()]
        );
        $this->addSql(
            "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES (?, 'backup.auto_max_size_mb', 'integer', '0', 1, 0, 0, 0, 1048576, NULL, 'settings.category.backup', 6, 20)",
            [Uuid::v4()->toBinary()]
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key IN ('backup.auto_policy', 'backup.auto_max_size_mb'))");
        $this->addSql("DELETE FROM setting_definition WHERE key IN ('backup.auto_policy', 'backup.auto_max_size_mb')");
    }
}
