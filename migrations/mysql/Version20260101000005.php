<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade tablas de sanciones con medidas disciplinarias (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_measure_category (
                id                    BINARY(16)   NOT NULL,
                educational_centre_id BINARY(16)   NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                position              INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                INDEX idx_smc_centre (educational_centre_id),
                CONSTRAINT fk_smc_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_measure (
                id                    BINARY(16)   NOT NULL,
                educational_centre_id BINARY(16)   NOT NULL,
                category_id           BINARY(16)   NOT NULL,
                name                  VARCHAR(500) NOT NULL,
                has_date_range        TINYINT(1)   NOT NULL DEFAULT 0,
                position              INT          NOT NULL DEFAULT 0,
                active                TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_sm_centre   (educational_centre_id),
                INDEX idx_sm_category (category_id),
                CONSTRAINT fk_sm_centre   FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_sm_category FOREIGN KEY (category_id)           REFERENCES sanction_measure_category (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction (
                id                 BINARY(16)  NOT NULL,
                academic_year_id   BINARY(16)  NOT NULL,
                student_id         BINARY(16)  NOT NULL,
                group_id           BINARY(16)  NOT NULL,
                registered_by_id   BINARY(16)  NOT NULL,
                created_at         DATETIME    NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                details            LONGTEXT    NOT NULL,
                no_measure_applied TINYINT(1)  NOT NULL DEFAULT 0,
                no_measure_reason  LONGTEXT    DEFAULT NULL,
                effective_from     DATE        DEFAULT NULL,
                effective_to       DATE        DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_sanction_academic_year (academic_year_id),
                INDEX idx_sanction_student       (student_id),
                INDEX idx_sanction_group         (group_id),
                INDEX idx_sanction_teacher       (registered_by_id),
                CONSTRAINT fk_sanction_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_sanction_student       FOREIGN KEY (student_id)       REFERENCES student (id),
                CONSTRAINT fk_sanction_group         FOREIGN KEY (group_id)         REFERENCES `group` (id),
                CONSTRAINT fk_sanction_teacher       FOREIGN KEY (registered_by_id) REFERENCES teacher (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_sanction_measure (
                sanction_id         BINARY(16) NOT NULL,
                sanction_measure_id BINARY(16) NOT NULL,
                PRIMARY KEY (sanction_id, sanction_measure_id),
                CONSTRAINT fk_ssm_sanction FOREIGN KEY (sanction_id)         REFERENCES sanction (id) ON DELETE CASCADE,
                CONSTRAINT fk_ssm_measure  FOREIGN KEY (sanction_measure_id) REFERENCES sanction_measure (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql('ALTER TABLE incident_report ADD COLUMN sanction_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE incident_report ADD CONSTRAINT fk_ir_sanction FOREIGN KEY (sanction_id) REFERENCES sanction (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_ir_sanction ON incident_report (sanction_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql('ALTER TABLE incident_report DROP FOREIGN KEY fk_ir_sanction');
        $this->addSql('ALTER TABLE incident_report DROP INDEX idx_ir_sanction');
        $this->addSql('ALTER TABLE incident_report DROP COLUMN sanction_id');
        $this->addSql('DROP TABLE sanction_sanction_measure');
        $this->addSql('DROP TABLE sanction');
        $this->addSql('DROP TABLE sanction_measure');
        $this->addSql('DROP TABLE sanction_measure_category');
    }
}
