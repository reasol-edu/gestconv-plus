<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000074 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Utilidades › Generador de calendarios: secuencias (printable_calendar_sequence y printable_calendar_sequence_item) (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('CREATE TABLE printable_calendar_sequence (id UUID NOT NULL, name VARCHAR(255) NOT NULL, start_date DATE NOT NULL, color_a VARCHAR(7) NOT NULL, color_b VARCHAR(7) NOT NULL, monday_hours DOUBLE PRECISION DEFAULT NULL, tuesday_hours DOUBLE PRECISION DEFAULT NULL, wednesday_hours DOUBLE PRECISION DEFAULT NULL, thursday_hours DOUBLE PRECISION DEFAULT NULL, friday_hours DOUBLE PRECISION DEFAULT NULL, show_hours BOOLEAN DEFAULT true NOT NULL, show_journey_summary BOOLEAN DEFAULT true NOT NULL, position INT NOT NULL, calendar_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1791A518A40A2C8 ON printable_calendar_sequence (calendar_id)');
        $this->addSql('CREATE TABLE printable_calendar_sequence_item (id UUID NOT NULL, description VARCHAR(255) NOT NULL, hours DOUBLE PRECISION NOT NULL, position INT NOT NULL, sequence_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9149362098FB19AE ON printable_calendar_sequence_item (sequence_id)');
        $this->addSql('ALTER TABLE printable_calendar_sequence ADD CONSTRAINT FK_1791A518A40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE printable_calendar_sequence_item ADD CONSTRAINT FK_9149362098FB19AE FOREIGN KEY (sequence_id) REFERENCES printable_calendar_sequence (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE printable_calendar_sequence_item DROP CONSTRAINT FK_9149362098FB19AE');
        $this->addSql('ALTER TABLE printable_calendar_sequence DROP CONSTRAINT FK_1791A518A40A2C8');
        $this->addSql('DROP TABLE printable_calendar_sequence_item');
        $this->addSql('DROP TABLE printable_calendar_sequence');
    }
}
