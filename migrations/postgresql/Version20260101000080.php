<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000080 extends AbstractMigration
{
    private const array KEYS = [
        'reports.student_header_left', 'reports.student_header_right', 'reports.student_header_margin', 'reports.student_footer', 'reports.student_pdf_template',
    ];

    public function getDescription(): string
    {
        return 'Ajustes del informe PDF del estudiante: encabezado, margen, pie y plantilla (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (gen_random_uuid(), 'reports.student_header_left',   'richtext', '<p><strong>{title}</strong></p>', TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 300),
                (gen_random_uuid(), 'reports.student_header_right',  'richtext', '<p>{centre_name}</p>',            TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 310),
                (gen_random_uuid(), 'reports.student_header_margin', 'integer',  '22',                              TRUE, TRUE, FALSE, 10, 80,  'settings.category.reports', 60, 320),
                (gen_random_uuid(), 'reports.student_footer',        'richtext', '<p>En {city} a {current_day} de {current_month_name} de {current_year}</p>', TRUE, TRUE, FALSE, 0, 5000, 'settings.category.reports', 60, 330),
                (gen_random_uuid(), 'reports.student_pdf_template',  'pdf',      '',                                FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 130)
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
