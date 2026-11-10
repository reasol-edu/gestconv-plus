<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000072 extends AbstractMigration
{
    private const array KEYS = [
        'features.utilities',
        'reports.printable_calendar_pdf_template_portrait',
        'reports.printable_calendar_pdf_template_landscape',
    ];

    public function getDescription(): string
    {
        return 'Utilidades › Generador de calendarios: printable_calendar, printable_calendar_date y printable_calendar_period, y sus plantillas PDF (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('CREATE TABLE printable_calendar (id UUID NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, non_working_day_color VARCHAR(7) DEFAULT NULL, weekend_color VARCHAR(7) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, educational_centre_id UUID NOT NULL, academic_year_id UUID NOT NULL, owner_id UUID NOT NULL, show_header BOOLEAN NOT NULL DEFAULT true, show_footer BOOLEAN NOT NULL DEFAULT true, orientation VARCHAR(255) NOT NULL DEFAULT \'portrait\', font_size_scale INT NOT NULL DEFAULT 100, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7A232D2F61F9EE23 ON printable_calendar (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_7A232D2FC54F3401 ON printable_calendar (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_7A232D2F7E3C61F9 ON printable_calendar (owner_id)');
        $this->addSql('CREATE TABLE printable_calendar_date (id UUID NOT NULL, date DATE NOT NULL, color VARCHAR(7) NOT NULL, description VARCHAR(255) NOT NULL, calendar_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9A9E192A40A2C8 ON printable_calendar_date (calendar_id)');
        $this->addSql('CREATE TABLE printable_calendar_period (id UUID NOT NULL, description VARCHAR(255) NOT NULL, color VARCHAR(7) NOT NULL, show_journey_summary BOOLEAN NOT NULL, mode VARCHAR(255) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, total_hours DOUBLE PRECISION DEFAULT NULL, monday_hours DOUBLE PRECISION DEFAULT NULL, tuesday_hours DOUBLE PRECISION DEFAULT NULL, wednesday_hours DOUBLE PRECISION DEFAULT NULL, thursday_hours DOUBLE PRECISION DEFAULT NULL, friday_hours DOUBLE PRECISION DEFAULT NULL, position INT NOT NULL, show_hours BOOLEAN NOT NULL DEFAULT true, calendar_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3CAFB3FCA40A2C8 ON printable_calendar_period (calendar_id)');
        $this->addSql('ALTER TABLE printable_calendar ADD CONSTRAINT FK_7A232D2F61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE printable_calendar ADD CONSTRAINT FK_7A232D2FC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE printable_calendar ADD CONSTRAINT FK_7A232D2F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE printable_calendar_date ADD CONSTRAINT FK_9A9E192A40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE printable_calendar_period ADD CONSTRAINT FK_3CAFB3FCA40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (gen_random_uuid(), 'features.utilities', 'boolean', 'true', TRUE, TRUE, FALSE, NULL, NULL, 'settings.category.features', 6, 80),
                (gen_random_uuid(), 'reports.printable_calendar_pdf_template_portrait',  'pdf', '', FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 110),
                (gen_random_uuid(), 'reports.printable_calendar_pdf_template_landscape', 'pdf', '', FALSE, TRUE, FALSE, NULL, NULL, 'settings.category.report_templates', 65, 120)
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

        $this->addSql('ALTER TABLE printable_calendar DROP CONSTRAINT FK_7A232D2F61F9EE23');
        $this->addSql('ALTER TABLE printable_calendar DROP CONSTRAINT FK_7A232D2FC54F3401');
        $this->addSql('ALTER TABLE printable_calendar DROP CONSTRAINT FK_7A232D2F7E3C61F9');
        $this->addSql('ALTER TABLE printable_calendar_date DROP CONSTRAINT FK_9A9E192A40A2C8');
        $this->addSql('ALTER TABLE printable_calendar_period DROP CONSTRAINT FK_3CAFB3FCA40A2C8');
        $this->addSql('DROP TABLE printable_calendar_period');
        $this->addSql('DROP TABLE printable_calendar_date');
        $this->addSql('DROP TABLE printable_calendar');
    }
}
