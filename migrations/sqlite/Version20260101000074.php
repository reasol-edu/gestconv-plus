<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000074 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Utilidades › Generador de calendarios: secuencias (printable_calendar_sequence y printable_calendar_sequence_item) (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('CREATE TABLE printable_calendar_sequence (id BLOB NOT NULL, name VARCHAR(255) NOT NULL, start_date DATE NOT NULL, color_a VARCHAR(7) NOT NULL, color_b VARCHAR(7) NOT NULL, monday_hours DOUBLE PRECISION DEFAULT NULL, tuesday_hours DOUBLE PRECISION DEFAULT NULL, wednesday_hours DOUBLE PRECISION DEFAULT NULL, thursday_hours DOUBLE PRECISION DEFAULT NULL, friday_hours DOUBLE PRECISION DEFAULT NULL, show_hours BOOLEAN DEFAULT 1 NOT NULL, show_journey_summary BOOLEAN DEFAULT 1 NOT NULL, position INTEGER NOT NULL, calendar_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_1791A518A40A2C8 FOREIGN KEY (calendar_id) REFERENCES printable_calendar (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_1791A518A40A2C8 ON printable_calendar_sequence (calendar_id)');
        $this->addSql('CREATE TABLE printable_calendar_sequence_item (id BLOB NOT NULL, description VARCHAR(255) NOT NULL, hours DOUBLE PRECISION NOT NULL, position INTEGER NOT NULL, sequence_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_9149362098FB19AE FOREIGN KEY (sequence_id) REFERENCES printable_calendar_sequence (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9149362098FB19AE ON printable_calendar_sequence_item (sequence_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE printable_calendar_sequence_item');
        $this->addSql('DROP TABLE printable_calendar_sequence');
    }
}
