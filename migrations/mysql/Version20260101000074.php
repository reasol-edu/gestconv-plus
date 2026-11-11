<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000074 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Utilidades › Generador de calendarios: secuencias (printable_calendar_sequence y printable_calendar_sequence_item) (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('CREATE TABLE printable_calendar_sequence (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, start_date DATE NOT NULL, color_a VARCHAR(7) NOT NULL, color_b VARCHAR(7) NOT NULL, monday_hours DOUBLE PRECISION DEFAULT NULL, tuesday_hours DOUBLE PRECISION DEFAULT NULL, wednesday_hours DOUBLE PRECISION DEFAULT NULL, thursday_hours DOUBLE PRECISION DEFAULT NULL, friday_hours DOUBLE PRECISION DEFAULT NULL, show_hours TINYINT(1) DEFAULT 1 NOT NULL, show_journey_summary TINYINT(1) DEFAULT 1 NOT NULL, position INT NOT NULL, calendar_id BINARY(16) NOT NULL, INDEX IDX_1791A518A40A2C8 (calendar_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE printable_calendar_sequence_item (id BINARY(16) NOT NULL, description VARCHAR(255) NOT NULL, hours DOUBLE PRECISION NOT NULL, position INT NOT NULL, sequence_id BINARY(16) NOT NULL, INDEX IDX_9149362098FB19AE (sequence_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE printable_calendar_sequence ADD CONSTRAINT FK_1791A518A40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE printable_calendar_sequence_item ADD CONSTRAINT FK_9149362098FB19AE FOREIGN KEY (sequence_id) REFERENCES printable_calendar_sequence (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE printable_calendar_sequence_item DROP FOREIGN KEY FK_9149362098FB19AE');
        $this->addSql('ALTER TABLE printable_calendar_sequence DROP FOREIGN KEY FK_1791A518A40A2C8');
        $this->addSql('DROP TABLE printable_calendar_sequence_item');
        $this->addSql('DROP TABLE printable_calendar_sequence');
    }
}
