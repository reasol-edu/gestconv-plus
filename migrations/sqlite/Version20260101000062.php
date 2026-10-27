<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000062 extends AbstractMigration
{
    private const array SETTING_IDS = [
        'reports.school_event_header_left'   => '4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f0a',
        'reports.school_event_header_right'  => '4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f0b',
        'reports.school_event_header_margin' => '4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f0c',
        'reports.school_event_footer'        => '4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f0d',
        'reports.school_event_pdf_template'  => '4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f0e',
    ];

    public function getDescription(): string
    {
        return 'Actividades en borrador (school_event.draft) y ajustes del informe PDF de una actividad: encabezado, margen, pie y plantilla (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE school_event ADD COLUMN draft INTEGER NOT NULL DEFAULT 0');

        $ids = self::SETTING_IDS;
        $this->addSql(<<<SQL
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                ('{$ids['reports.school_event_header_left']}',   'reports.school_event_header_left',   'richtext', '<p><strong>{title}</strong></p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 160),
                ('{$ids['reports.school_event_header_right']}',  'reports.school_event_header_right',  'richtext', '<p>{centre_name}</p>',            1, 1, 0, 0, 5000, 'settings.category.reports', 60, 170),
                ('{$ids['reports.school_event_header_margin']}', 'reports.school_event_header_margin', 'integer',  '22',                              1, 1, 0, 10, 80,  'settings.category.reports', 60, 180),
                ('{$ids['reports.school_event_footer']}',        'reports.school_event_footer',        'richtext', '<p>En {city} a {current_day} de {current_month_name} de {current_year}</p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 190),
                ('{$ids['reports.school_event_pdf_template']}',  'reports.school_event_pdf_template',  'pdf',      '',                                0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 70)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        foreach (array_keys(self::SETTING_IDS) as $key) {
            $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
            $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
            $this->addSql("DELETE FROM setting_definition WHERE key = '$key'");
        }

        $this->addSql('ALTER TABLE school_event DROP COLUMN draft');
    }
}
