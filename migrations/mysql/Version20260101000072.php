<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
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
        return 'Utilidades › Generador de calendarios: printable_calendar, printable_calendar_date y printable_calendar_period, y sus plantillas PDF (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('CREATE TABLE printable_calendar (id BINARY(16) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, non_working_day_color VARCHAR(7) DEFAULT NULL, weekend_color VARCHAR(7) DEFAULT NULL, created_at DATETIME NOT NULL, educational_centre_id BINARY(16) NOT NULL, academic_year_id BINARY(16) NOT NULL, owner_id BINARY(16) NOT NULL, show_header TINYINT(1) NOT NULL DEFAULT 1, show_footer TINYINT(1) NOT NULL DEFAULT 1, orientation VARCHAR(255) NOT NULL DEFAULT \'portrait\', font_size_scale INT NOT NULL DEFAULT 100, INDEX IDX_7A232D2F61F9EE23 (educational_centre_id), INDEX IDX_7A232D2FC54F3401 (academic_year_id), INDEX IDX_7A232D2F7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE printable_calendar_date (id BINARY(16) NOT NULL, date DATE NOT NULL, color VARCHAR(7) NOT NULL, description VARCHAR(255) NOT NULL, calendar_id BINARY(16) NOT NULL, INDEX IDX_9A9E192A40A2C8 (calendar_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE printable_calendar_period (id BINARY(16) NOT NULL, description VARCHAR(255) NOT NULL, color VARCHAR(7) NOT NULL, show_journey_summary TINYINT NOT NULL, mode VARCHAR(255) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, total_hours DOUBLE PRECISION DEFAULT NULL, monday_hours DOUBLE PRECISION DEFAULT NULL, tuesday_hours DOUBLE PRECISION DEFAULT NULL, wednesday_hours DOUBLE PRECISION DEFAULT NULL, thursday_hours DOUBLE PRECISION DEFAULT NULL, friday_hours DOUBLE PRECISION DEFAULT NULL, position INT NOT NULL, show_hours TINYINT(1) NOT NULL DEFAULT 1, calendar_id BINARY(16) NOT NULL, INDEX IDX_3CAFB3FCA40A2C8 (calendar_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE printable_calendar ADD CONSTRAINT FK_7A232D2F61F9EE23 FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE printable_calendar ADD CONSTRAINT FK_7A232D2FC54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE printable_calendar ADD CONSTRAINT FK_7A232D2F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES teacher (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE printable_calendar_date ADD CONSTRAINT FK_9A9E192A40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE printable_calendar_period ADD CONSTRAINT FK_3CAFB3FCA40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'features.utilities', 'boolean', 'true', 1, 1, 0, NULL, NULL, 'settings.category.features', 6, 80),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.printable_calendar_pdf_template_portrait',  'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 110),
                (UNHEX(REPLACE(UUID(), '-', '')), 'reports.printable_calendar_pdf_template_landscape', 'pdf', '', 0, 1, 0, NULL, NULL, 'settings.category.report_templates', 65, 120)
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

        $this->addSql('ALTER TABLE printable_calendar DROP FOREIGN KEY FK_7A232D2F61F9EE23');
        $this->addSql('ALTER TABLE printable_calendar DROP FOREIGN KEY FK_7A232D2FC54F3401');
        $this->addSql('ALTER TABLE printable_calendar DROP FOREIGN KEY FK_7A232D2F7E3C61F9');
        $this->addSql('ALTER TABLE printable_calendar_date DROP FOREIGN KEY FK_9A9E192A40A2C8');
        $this->addSql('ALTER TABLE printable_calendar_period DROP FOREIGN KEY FK_3CAFB3FCA40A2C8');
        $this->addSql('DROP TABLE printable_calendar_period');
        $this->addSql('DROP TABLE printable_calendar_date');
        $this->addSql('DROP TABLE printable_calendar');
    }
}
