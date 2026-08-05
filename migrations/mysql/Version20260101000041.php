<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000041 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade los ajustes de plantilla PDF de informes, en su propia categoría justo detrás de Personalización de informes: dos generales (vertical/apaisada) y uno por tipo de informe (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.pdf_template_portrait',    'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 10),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.pdf_template_landscape',   'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 20),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.incident_pdf_template',    'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 30),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.sanction_pdf_template',    'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 40),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.group_stats_pdf_template', 'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 50),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.guard_duty_pdf_template',  'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 60)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('reports.pdf_template_portrait', 'reports.pdf_template_landscape', 'reports.incident_pdf_template', 'reports.sanction_pdf_template', 'reports.group_stats_pdf_template', 'reports.guard_duty_pdf_template')");
    }
}
