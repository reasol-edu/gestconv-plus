<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Encabezados de PDF personalizables: valores de ajuste como TEXT y ajustes de personalización de informes (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE setting_definition ALTER COLUMN default_value TYPE TEXT');
        $this->addSql('ALTER TABLE global_setting_value ALTER COLUMN value TYPE TEXT');
        $this->addSql('ALTER TABLE centre_setting_value ALTER COLUMN value TYPE TEXT');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (gen_random_uuid(), 'reports.incident_header_left',   'richtext', '<p><strong>{title}</strong></p>', TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 10),
                (gen_random_uuid(), 'reports.incident_header_right',  'richtext', '<p>{centre_name}</p>',            TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 20),
                (gen_random_uuid(), 'reports.incident_header_margin', 'integer',  '22',                              TRUE, TRUE, FALSE, 10, 80,  'settings.category.reports', 60, 30),
                (gen_random_uuid(), 'reports.sanction_header_left',   'richtext', '<p><strong>{title}</strong></p>', TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 40),
                (gen_random_uuid(), 'reports.sanction_header_right',  'richtext', '<p>{centre_name}</p>',            TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 50),
                (gen_random_uuid(), 'reports.sanction_header_margin', 'integer',  '22',                              TRUE, TRUE, FALSE, 10, 80,  'settings.category.reports', 60, 60)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('reports.incident_header_left', 'reports.incident_header_right', 'reports.incident_header_margin', 'reports.sanction_header_left', 'reports.sanction_header_right', 'reports.sanction_header_margin')");

        $this->addSql('ALTER TABLE centre_setting_value ALTER COLUMN value TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE global_setting_value ALTER COLUMN value TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE setting_definition ALTER COLUMN default_value TYPE VARCHAR(255)');
    }
}
