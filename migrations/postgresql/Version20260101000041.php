<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000041 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade los ajustes de plantilla PDF de informes, en su propia categoría justo detrás de Personalización de informes: dos generales (vertical/apaisada) y uno por tipo de informe (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (gen_random_uuid(), 'reports.pdf_template_portrait',     'pdf', '', FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 10),
                (gen_random_uuid(), 'reports.pdf_template_landscape',    'pdf', '', FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 20),
                (gen_random_uuid(), 'reports.incident_pdf_template',     'pdf', '', FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 30),
                (gen_random_uuid(), 'reports.sanction_pdf_template',     'pdf', '', FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 40),
                (gen_random_uuid(), 'reports.group_stats_pdf_template',  'pdf', '', FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 50),
                (gen_random_uuid(), 'reports.guard_duty_pdf_template',   'pdf', '', FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 60)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('reports.pdf_template_portrait', 'reports.pdf_template_landscape', 'reports.incident_pdf_template', 'reports.sanction_pdf_template', 'reports.group_stats_pdf_template', 'reports.guard_duty_pdf_template')");
    }
}
