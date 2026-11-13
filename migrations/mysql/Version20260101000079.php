<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000079 extends AbstractMigration
{
    private const string KEY = 'display.draft_retention_hours';

    public function getDescription(): string
    {
        return 'Ajuste global «Caducidad de los borradores de partes» (horas; 0 = sin borradores) (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'display.draft_retention_hours', 'integer', '24', 1, 0, 0, 0, 168, 'settings.category.display', 10, 30)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $key = self::KEY;
        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM $table WHERE definition_id IN (SELECT id FROM (SELECT id FROM setting_definition WHERE `key` = '$key') d)");
        }
        $this->addSql("DELETE FROM setting_definition WHERE `key` = '$key'");
    }
}
