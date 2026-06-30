<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade tablas de partes de convivencia con categorías de conducta (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_behavior_category (
                id                    BINARY(16)   NOT NULL,
                educational_centre_id BINARY(16)   NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                serious               TINYINT(1)   NOT NULL DEFAULT 0,
                position              INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                INDEX idx_incident_behavior_category_centre (educational_centre_id),
                CONSTRAINT fk_ibc_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_behavior (
                id                    BINARY(16)   NOT NULL,
                educational_centre_id BINARY(16)   NOT NULL,
                category_id           BINARY(16)   NOT NULL,
                name                  VARCHAR(500) NOT NULL,
                position              INT          NOT NULL DEFAULT 0,
                active                TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_incident_behavior_centre   (educational_centre_id),
                INDEX idx_incident_behavior_category (category_id),
                CONSTRAINT fk_incident_behavior_centre   FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_incident_behavior_category FOREIGN KEY (category_id)           REFERENCES incident_behavior_category (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_report (
                id                  BINARY(16)        NOT NULL,
                academic_year_id    BINARY(16)        NOT NULL,
                student_id          BINARY(16)        NOT NULL,
                group_id            BINARY(16)        NOT NULL,
                registered_by_id    BINARY(16)        NOT NULL,
                number              SMALLINT UNSIGNED NOT NULL,
                occurred_at         DATETIME          NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                description         LONGTEXT          NOT NULL,
                expelled_from_class TINYINT(1)        NOT NULL DEFAULT 0,
                assigned_tasks      LONGTEXT          DEFAULT NULL,
                tasks_completed     VARCHAR(10)       DEFAULT NULL,
                prescribed_at       DATE              DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ir_year_number          (academic_year_id, number),
                INDEX idx_incident_report_academic_year (academic_year_id),
                INDEX idx_incident_report_student       (student_id),
                INDEX idx_incident_report_group         (group_id),
                INDEX idx_incident_report_teacher       (registered_by_id),
                INDEX idx_incident_report_occurred      (occurred_at),
                CONSTRAINT fk_ir_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_ir_student       FOREIGN KEY (student_id)       REFERENCES student (id),
                CONSTRAINT fk_ir_group         FOREIGN KEY (group_id)         REFERENCES `group` (id),
                CONSTRAINT fk_ir_teacher       FOREIGN KEY (registered_by_id) REFERENCES teacher (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_report_behavior (
                incident_report_id   BINARY(16) NOT NULL,
                incident_behavior_id BINARY(16) NOT NULL,
                PRIMARY KEY (incident_report_id, incident_behavior_id),
                CONSTRAINT fk_irb_report   FOREIGN KEY (incident_report_id)   REFERENCES incident_report (id) ON DELETE CASCADE,
                CONSTRAINT fk_irb_behavior FOREIGN KEY (incident_behavior_id) REFERENCES incident_behavior (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql('DROP TABLE incident_report_behavior');
        $this->addSql('DROP TABLE incident_report');
        $this->addSql('DROP TABLE incident_behavior');
        $this->addSql('DROP TABLE incident_behavior_category');
    }
}
