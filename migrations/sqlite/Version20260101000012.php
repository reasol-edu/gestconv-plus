<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla incident_report_observation (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_report_observation (
                id                  CHAR(36) NOT NULL,
                incident_report_id  CHAR(36) NOT NULL,
                registered_by_id    CHAR(36) NOT NULL,
                registered_at       DATETIME NOT NULL,
                text                CLOB     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_iro_report        FOREIGN KEY (incident_report_id) REFERENCES incident_report (id) ON DELETE CASCADE,
                CONSTRAINT fk_iro_registered_by FOREIGN KEY (registered_by_id)   REFERENCES teacher (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_iro_report        ON incident_report_observation (incident_report_id)');
        $this->addSql('CREATE INDEX idx_iro_registered_by ON incident_report_observation (registered_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE incident_report_observation');
    }
}
