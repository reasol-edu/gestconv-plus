<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000069 extends AbstractMigration
{
    private const array KEYS = [
        'reports.issue_header_left', 'reports.issue_header_right', 'reports.issue_header_margin', 'reports.issue_footer', 'reports.issue_pdf_template',
    ];

    public function getDescription(): string
    {
        return 'Ajustes del informe PDF de una incidencia: encabezado, margen, pie y plantilla (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.issue_header_left',   'richtext', '<p><strong>{title}</strong></p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 200),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.issue_header_right',  'richtext', '<p>{centre_name}</p>',            1, 1, 0, 0, 5000, 'settings.category.reports', 60, 210),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.issue_header_margin', 'integer',  '22',                              1, 1, 0, 10, 80,  'settings.category.reports', 60, 220),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.issue_footer',        'richtext', '<p>En {city} a {current_day} de {current_month_name} de {current_year}</p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 230),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.issue_pdf_template',  'pdf',      '',                                0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 80)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        foreach (self::KEYS as $key) {
            $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM (SELECT id FROM setting_definition WHERE `key` = '$key') d)");
            $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM (SELECT id FROM setting_definition WHERE `key` = '$key') d)");
            $this->addSql("DELETE FROM setting_definition WHERE `key` = '$key'");
        }
    }
}
