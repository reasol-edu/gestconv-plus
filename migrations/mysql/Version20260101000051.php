<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes features.* para activar o desactivar Salidas, Ausencias, Notas y tareas de sanción, a nivel global y de centro (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'features.exits',          'boolean', 'true', 1, 1, 0, NULL, NULL, NULL, 'settings.category.features', 6, 10),
                (UNHEX(REPLACE(UUID(), '-', '')), 'features.absences',       'boolean', 'true', 1, 1, 0, NULL, NULL, NULL, 'settings.category.features', 6, 20),
                (UNHEX(REPLACE(UUID(), '-', '')), 'features.daily_notes',    'boolean', 'true', 1, 1, 0, NULL, NULL, NULL, 'settings.category.features', 6, 30),
                (UNHEX(REPLACE(UUID(), '-', '')), 'features.sanction_tasks', 'boolean', 'true', 1, 1, 0, NULL, NULL, NULL, 'settings.category.features', 6, 40)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $keys = "'features.exits', 'features.absences', 'features.daily_notes', 'features.sanction_tasks'";
        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` IN ($keys))");
        $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` IN ($keys))");
        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ($keys)");
    }
}
