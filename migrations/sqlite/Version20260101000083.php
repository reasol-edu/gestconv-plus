<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000083 extends AbstractMigration
{
    /** Ajustes del PDF de la analítica: clave, tipo, valor por defecto, ámbito global, mínimo, máximo, categoría, orden de categoría y posición. */
    private const array SETTINGS = [
        ['reports.analytics_header_left', 'richtext', '<p><strong>{title}</strong></p>', 1, 0, 5000, 'settings.category.reports', 60, 340],
        ['reports.analytics_header_right', 'richtext', '<p>{centre_name}</p>', 1, 0, 5000, 'settings.category.reports', 60, 350],
        ['reports.analytics_header_margin', 'integer', '22', 1, 10, 80, 'settings.category.reports', 60, 360],
        ['reports.analytics_pdf_template', 'pdf', '', 0, null, null, 'settings.category.report_templates', 65, 140],
    ];

    public function getDescription(): string
    {
        return 'Ajustes del PDF de la analítica de convivencia: encabezado, margen y plantilla (SQLite)';
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
