<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000071 extends AbstractMigration
{
    /** Ajustes de los informes PDF de salidas e incidencias: clave, tipo, valor por defecto, ámbito global, mínimo, máximo, categoría, orden de categoría y posición. */
    private const array SETTINGS = [
        ['reports.exit_stats_header_left', 'richtext', '<p><strong>{title}</strong></p>', 1, 0, 5000, 'settings.category.reports', 60, 240],
        ['reports.exit_stats_header_right', 'richtext', '<p>{centre_name}</p>', 1, 0, 5000, 'settings.category.reports', 60, 250],
        ['reports.exit_stats_header_margin', 'integer', '22', 1, 10, 80, 'settings.category.reports', 60, 260],
        ['reports.issue_stats_header_left', 'richtext', '<p><strong>{title}</strong></p>', 1, 0, 5000, 'settings.category.reports', 60, 270],
        ['reports.issue_stats_header_right', 'richtext', '<p>{centre_name}</p>', 1, 0, 5000, 'settings.category.reports', 60, 280],
        ['reports.issue_stats_header_margin', 'integer', '22', 1, 10, 80, 'settings.category.reports', 60, 290],
        ['reports.exit_stats_pdf_template', 'pdf', '', 0, null, null, 'settings.category.report_templates', 65, 90],
        ['reports.issue_stats_pdf_template', 'pdf', '', 0, null, null, 'settings.category.report_templates', 65, 100],
    ];

    public function getDescription(): string
    {
        return 'Ajustes de los informes PDF de salidas e incidencias: encabezado, margen y plantilla (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // Los ids van como parámetro con los 16 bytes del UUID, que es como los envía Doctrine en SQLite (ver la migración 050).
        foreach (self::SETTINGS as [$key, $type, $default, $global, $min, $max, $category, $categoryOrder, $position]) {
            $this->addSql(
                'INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES (?, ?, ?, ?, ?, 1, 0, ?, ?, NULL, ?, ?, ?)',
                [Uuid::v4()->toBinary(), $key, $type, $default, $global, $min, $max, $category, $categoryOrder, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        foreach (self::SETTINGS as [$key]) {
            $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
            $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
            $this->addSql("DELETE FROM setting_definition WHERE key = '$key'");
        }
    }
}
