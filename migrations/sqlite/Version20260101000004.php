<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade tablas de partes de convivencia con categorías de conducta (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_behavior_category (
                id                    CHAR(36)     NOT NULL,
                educational_centre_id CHAR(36)     NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                serious               INTEGER      NOT NULL DEFAULT 0,
                position              INTEGER      NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                CONSTRAINT fk_ibc_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_incident_behavior_category_centre ON incident_behavior_category (educational_centre_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_behavior (
                id                    CHAR(36)     NOT NULL,
                educational_centre_id CHAR(36)     NOT NULL,
                category_id           CHAR(36)     NOT NULL,
                name                  VARCHAR(500) NOT NULL,
                position              INTEGER      NOT NULL DEFAULT 0,
                active                INTEGER      NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_incident_behavior_centre   FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_incident_behavior_category FOREIGN KEY (category_id)           REFERENCES incident_behavior_category (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_incident_behavior_centre   ON incident_behavior (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_incident_behavior_category ON incident_behavior (category_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_report (
                id                  CHAR(36) NOT NULL,
                academic_year_id    CHAR(36) NOT NULL,
                student_id          CHAR(36) NOT NULL,
                group_id            CHAR(36) NOT NULL,
                registered_by_id    CHAR(36) NOT NULL,
                number              INTEGER  NOT NULL,
                occurred_at         DATETIME NOT NULL,
                description         CLOB     NOT NULL,
                expelled_from_class INTEGER  NOT NULL DEFAULT 0,
                assigned_tasks      CLOB     DEFAULT NULL,
                tasks_completed     VARCHAR(10) DEFAULT NULL,
                prescribed_at       DATE        DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_ir_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_ir_student       FOREIGN KEY (student_id)       REFERENCES student (id),
                CONSTRAINT fk_ir_group         FOREIGN KEY (group_id)         REFERENCES "group" (id),
                CONSTRAINT fk_ir_teacher       FOREIGN KEY (registered_by_id) REFERENCES teacher (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_ir_year_number            ON incident_report (academic_year_id, number)');
        $this->addSql('CREATE INDEX idx_incident_report_academic_year   ON incident_report (academic_year_id)');
        $this->addSql('CREATE INDEX idx_incident_report_student         ON incident_report (student_id)');
        $this->addSql('CREATE INDEX idx_incident_report_group           ON incident_report (group_id)');
        $this->addSql('CREATE INDEX idx_incident_report_teacher         ON incident_report (registered_by_id)');
        $this->addSql('CREATE INDEX idx_incident_report_occurred        ON incident_report (occurred_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_report_behavior (
                incident_report_id   CHAR(36) NOT NULL,
                incident_behavior_id CHAR(36) NOT NULL,
                PRIMARY KEY (incident_report_id, incident_behavior_id),
                CONSTRAINT fk_irb_report   FOREIGN KEY (incident_report_id)   REFERENCES incident_report (id) ON DELETE CASCADE,
                CONSTRAINT fk_irb_behavior FOREIGN KEY (incident_behavior_id) REFERENCES incident_behavior (id) ON DELETE CASCADE
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE incident_report_behavior');
        $this->addSql('DROP TABLE incident_report');
        $this->addSql('DROP TABLE incident_behavior');
        $this->addSql('DROP TABLE incident_behavior_category');
    }
}
