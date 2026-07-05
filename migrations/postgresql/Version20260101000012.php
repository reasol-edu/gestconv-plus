<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla incident_report_observation (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_report_observation (
                id                  UUID         NOT NULL,
                incident_report_id  UUID         NOT NULL,
                registered_by_id    UUID         NOT NULL,
                registered_at       TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                text                TEXT         NOT NULL,
                created_at          TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN incident_report_observation.registered_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN incident_report_observation.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_iro_report        ON incident_report_observation (incident_report_id)');
        $this->addSql('CREATE INDEX idx_iro_registered_by ON incident_report_observation (registered_by_id)');
        $this->addSql('ALTER TABLE incident_report_observation ADD CONSTRAINT fk_iro_report        FOREIGN KEY (incident_report_id) REFERENCES incident_report (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE incident_report_observation ADD CONSTRAINT fk_iro_registered_by FOREIGN KEY (registered_by_id)   REFERENCES teacher (id)                          NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP TABLE incident_report_observation');
    }
}
