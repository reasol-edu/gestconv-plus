<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000072 extends AbstractMigration
{
    /** Interruptor de la sección y plantillas PDF del generador de calendarios: clave, tipo, valor por defecto, ámbito global, categoría, orden de categoría y posición. */
    private const array SETTINGS = [
        ['features.utilities', 'boolean', 'true', 1, 'settings.category.features', 6, 80],
        ['reports.printable_calendar_pdf_template_portrait', 'pdf', '', 0, 'settings.category.report_templates', 65, 110],
        ['reports.printable_calendar_pdf_template_landscape', 'pdf', '', 0, 'settings.category.report_templates', 65, 120],
    ];

    public function getDescription(): string
    {
        return 'Utilidades › Generador de calendarios: printable_calendar, printable_calendar_date y printable_calendar_period, y sus plantillas PDF (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('CREATE TABLE printable_calendar (id BLOB NOT NULL, title VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, non_working_day_color VARCHAR(7) DEFAULT NULL, weekend_color VARCHAR(7) DEFAULT NULL, created_at DATETIME NOT NULL, educational_centre_id BLOB NOT NULL, academic_year_id BLOB NOT NULL, owner_id BLOB NOT NULL, show_header BOOLEAN NOT NULL DEFAULT 1, show_footer BOOLEAN NOT NULL DEFAULT 1, orientation VARCHAR(255) NOT NULL DEFAULT \'portrait\', font_size_scale INTEGER NOT NULL DEFAULT 100, PRIMARY KEY (id), CONSTRAINT FK_7A232D2F61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A232D2FC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_7A232D2F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7A232D2F61F9EE23 ON printable_calendar (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_7A232D2FC54F3401 ON printable_calendar (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_7A232D2F7E3C61F9 ON printable_calendar (owner_id)');
        $this->addSql('CREATE TABLE printable_calendar_date (id BLOB NOT NULL, date DATE NOT NULL, color VARCHAR(7) NOT NULL, description VARCHAR(255) NOT NULL, calendar_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_9A9E192A40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9A9E192A40A2C8 ON printable_calendar_date (calendar_id)');
        $this->addSql('CREATE TABLE printable_calendar_period (id BLOB NOT NULL, description VARCHAR(255) NOT NULL, color VARCHAR(7) NOT NULL, show_journey_summary BOOLEAN NOT NULL, mode VARCHAR(255) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, total_hours DOUBLE PRECISION DEFAULT NULL, monday_hours DOUBLE PRECISION DEFAULT NULL, tuesday_hours DOUBLE PRECISION DEFAULT NULL, wednesday_hours DOUBLE PRECISION DEFAULT NULL, thursday_hours DOUBLE PRECISION DEFAULT NULL, friday_hours DOUBLE PRECISION DEFAULT NULL, position INTEGER NOT NULL, show_hours BOOLEAN NOT NULL DEFAULT 1, calendar_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_3CAFB3FCA40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_3CAFB3FCA40A2C8 ON printable_calendar_period (calendar_id)');

        // Los ids van como parámetro con los 16 bytes del UUID, que es como los envía Doctrine en SQLite (ver la migración 050).
        foreach (self::SETTINGS as [$key, $type, $default, $global, $category, $categoryOrder, $position]) {
            $this->addSql(
                'INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES (?, ?, ?, ?, ?, 1, 0, NULL, NULL, NULL, ?, ?, ?)',
                [Uuid::v4()->toBinary(), $key, $type, $default, $global, $category, $categoryOrder, $position]
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

        $this->addSql('DROP TABLE printable_calendar_period');
        $this->addSql('DROP TABLE printable_calendar_date');
        $this->addSql('DROP TABLE printable_calendar');
    }
}
