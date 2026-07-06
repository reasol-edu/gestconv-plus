<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000021 extends AbstractMigration
{
    public function getDescription(): string
    {
        // SQLite no impone la longitud de VARCHAR (afinidad TEXT), así que no
        // hay que ensanchar las columnas de valor como en MySQL/PostgreSQL.
        return 'Encabezados de PDF personalizables: ajustes de personalización de informes (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-000000000015', 'reports.incident_header_left',   'richtext', '<p><strong>{title}</strong></p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 10),
            ('00000000-0000-4000-8000-000000000016', 'reports.incident_header_right',  'richtext', '<p>{centre_name}</p>',            1, 1, 0, 0, 5000, 'settings.category.reports', 60, 20),
            ('00000000-0000-4000-8000-000000000017', 'reports.incident_header_margin', 'integer',  '22',                              1, 1, 0, 10, 80,  'settings.category.reports', 60, 30),
            ('00000000-0000-4000-8000-000000000018', 'reports.sanction_header_left',   'richtext', '<p><strong>{title}</strong></p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 40),
            ('00000000-0000-4000-8000-000000000019', 'reports.sanction_header_right',  'richtext', '<p>{centre_name}</p>',            1, 1, 0, 0, 5000, 'settings.category.reports', 60, 50),
            ('00000000-0000-4000-8000-00000000001a', 'reports.sanction_header_margin', 'integer',  '22',                              1, 1, 0, 10, 80,  'settings.category.reports', 60, 60)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof SqlitePlatform, 'Esta migración sólo puede ejecutarse en SQLite.');

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('reports.incident_header_left', 'reports.incident_header_right', 'reports.incident_header_margin', 'reports.sanction_header_left', 'reports.sanction_header_right', 'reports.sanction_header_margin')");
    }
}
