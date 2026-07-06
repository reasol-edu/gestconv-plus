<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Encabezados de PDF personalizables: valores de ajuste como TEXT y ajustes de personalización de informes (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE setting_definition MODIFY default_value TEXT NOT NULL');
        $this->addSql('ALTER TABLE global_setting_value MODIFY value TEXT NOT NULL');
        $this->addSql('ALTER TABLE centre_setting_value MODIFY value TEXT NOT NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.incident_header_left',   'richtext', '<p><strong>{title}</strong></p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 10),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.incident_header_right',  'richtext', '<p>{centre_name}</p>',            1, 1, 0, 0, 5000, 'settings.category.reports', 60, 20),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.incident_header_margin', 'integer',  '22',                              1, 1, 0, 10, 80,  'settings.category.reports', 60, 30),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.sanction_header_left',   'richtext', '<p><strong>{title}</strong></p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 40),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.sanction_header_right',  'richtext', '<p>{centre_name}</p>',            1, 1, 0, 0, 5000, 'settings.category.reports', 60, 50),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.sanction_header_margin', 'integer',  '22',                              1, 1, 0, 10, 80,  'settings.category.reports', 60, 60)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('reports.incident_header_left', 'reports.incident_header_right', 'reports.incident_header_margin', 'reports.sanction_header_left', 'reports.sanction_header_right', 'reports.sanction_header_margin')");

        $this->addSql('ALTER TABLE centre_setting_value MODIFY value VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE global_setting_value MODIFY value VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE setting_definition MODIFY default_value VARCHAR(255) NOT NULL');
    }
}
