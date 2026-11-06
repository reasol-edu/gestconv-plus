<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000071 extends AbstractMigration
{
    private const array KEYS = [
        'reports.exit_stats_header_left', 'reports.exit_stats_header_right', 'reports.exit_stats_header_margin',
        'reports.issue_stats_header_left', 'reports.issue_stats_header_right', 'reports.issue_stats_header_margin',
        'reports.exit_stats_pdf_template', 'reports.issue_stats_pdf_template',
    ];

    public function getDescription(): string
    {
        return 'Ajustes de los informes PDF de salidas e incidencias: encabezado, margen y plantilla (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (gen_random_uuid(), 'reports.exit_stats_header_left',   'richtext', '<p><strong>{title}</strong></p>', TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 240),
                (gen_random_uuid(), 'reports.exit_stats_header_right',  'richtext', '<p>{centre_name}</p>',            TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 250),
                (gen_random_uuid(), 'reports.exit_stats_header_margin', 'integer',  '22',                              TRUE, TRUE, FALSE, 10, 80,  'settings.category.reports', 60, 260),
                (gen_random_uuid(), 'reports.issue_stats_header_left',   'richtext', '<p><strong>{title}</strong></p>', TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 270),
                (gen_random_uuid(), 'reports.issue_stats_header_right',  'richtext', '<p>{centre_name}</p>',            TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 280),
                (gen_random_uuid(), 'reports.issue_stats_header_margin', 'integer',  '22',                              TRUE, TRUE, FALSE, 10, 80,  'settings.category.reports', 60, 290),
                (gen_random_uuid(), 'reports.exit_stats_pdf_template',   'pdf',      '',                                FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 90),
                (gen_random_uuid(), 'reports.issue_stats_pdf_template',  'pdf',      '',                                FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 100)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        foreach (self::KEYS as $key) {
            $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
            $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
            $this->addSql("DELETE FROM setting_definition WHERE key = '$key'");
        }
    }
}
