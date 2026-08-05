<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000041 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade los ajustes de plantilla PDF de informes, en su propia categoría justo detrás de Personalización de informes: dos generales (vertical/apaisada) y uno por tipo de informe (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-000000000032', 'reports.pdf_template_portrait',    'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 10),
            ('00000000-0000-4000-8000-000000000033', 'reports.pdf_template_landscape',   'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 20),
            ('00000000-0000-4000-8000-000000000034', 'reports.incident_pdf_template',    'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 30),
            ('00000000-0000-4000-8000-000000000035', 'reports.sanction_pdf_template',    'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 40),
            ('00000000-0000-4000-8000-000000000036', 'reports.group_stats_pdf_template', 'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 50),
            ('00000000-0000-4000-8000-000000000037', 'reports.guard_duty_pdf_template',  'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 60)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('reports.pdf_template_portrait', 'reports.pdf_template_landscape', 'reports.incident_pdf_template', 'reports.sanction_pdf_template', 'reports.group_stats_pdf_template', 'reports.guard_duty_pdf_template')");
    }
}
