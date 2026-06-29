<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade tablas de partes de convivencia (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_behavior (
                id                    UUID         NOT NULL,
                educational_centre_id UUID         NOT NULL,
                name                  VARCHAR(500) NOT NULL,
                position              INT          NOT NULL DEFAULT 0,
                serious               BOOLEAN      NOT NULL DEFAULT FALSE,
                active                BOOLEAN      NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_incident_behavior_centre ON incident_behavior (educational_centre_id)');
        $this->addSql('ALTER TABLE incident_behavior ADD CONSTRAINT fk_incident_behavior_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_report (
                id                  UUID      NOT NULL,
                student_id          UUID      NOT NULL,
                group_id            UUID      NOT NULL,
                registered_by_id    UUID      NOT NULL,
                occurred_at         TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                description         TEXT      NOT NULL,
                expelled_from_class BOOLEAN   NOT NULL DEFAULT FALSE,
                assigned_tasks      TEXT      DEFAULT NULL,
                tasks_completed     VARCHAR(10) DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN incident_report.occurred_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_incident_report_student  ON incident_report (student_id)');
        $this->addSql('CREATE INDEX idx_incident_report_group    ON incident_report (group_id)');
        $this->addSql('CREATE INDEX idx_incident_report_teacher  ON incident_report (registered_by_id)');
        $this->addSql('CREATE INDEX idx_incident_report_occurred ON incident_report (occurred_at)');
        $this->addSql('ALTER TABLE incident_report ADD CONSTRAINT fk_ir_student FOREIGN KEY (student_id)       REFERENCES student (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE incident_report ADD CONSTRAINT fk_ir_group   FOREIGN KEY (group_id)         REFERENCES "group" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE incident_report ADD CONSTRAINT fk_ir_teacher FOREIGN KEY (registered_by_id) REFERENCES teacher (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_report_behavior (
                incident_report_id   UUID NOT NULL,
                incident_behavior_id UUID NOT NULL,
                PRIMARY KEY (incident_report_id, incident_behavior_id)
            )
        SQL);
        $this->addSql('ALTER TABLE incident_report_behavior ADD CONSTRAINT fk_irb_report   FOREIGN KEY (incident_report_id)   REFERENCES incident_report (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE incident_report_behavior ADD CONSTRAINT fk_irb_behavior FOREIGN KEY (incident_behavior_id) REFERENCES incident_behavior (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP TABLE incident_report_behavior');
        $this->addSql('DROP TABLE incident_report');
        $this->addSql('DROP TABLE incident_behavior');
    }
}
