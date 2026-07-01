<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade tablas de sanciones con medidas disciplinarias (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_measure_category (
                id                    CHAR(36)     NOT NULL,
                educational_centre_id CHAR(36)     NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                position              INTEGER      NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                CONSTRAINT fk_smc_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_smc_centre ON sanction_measure_category (educational_centre_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_measure (
                id                    CHAR(36)     NOT NULL,
                educational_centre_id CHAR(36)     NOT NULL,
                category_id           CHAR(36)     NOT NULL,
                name                  VARCHAR(500) NOT NULL,
                has_date_range        INTEGER      NOT NULL DEFAULT 0,
                position              INTEGER      NOT NULL DEFAULT 0,
                active                INTEGER      NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_sm_centre   FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_sm_category FOREIGN KEY (category_id)           REFERENCES sanction_measure_category (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_sm_centre   ON sanction_measure (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_sm_category ON sanction_measure (category_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction (
                id                 CHAR(36)  NOT NULL,
                academic_year_id   CHAR(36)  NOT NULL,
                student_id         CHAR(36)  NOT NULL,
                group_id           CHAR(36)  NOT NULL,
                registered_by_id   CHAR(36)  NOT NULL,
                created_at         DATETIME  NOT NULL,
                details            CLOB      NOT NULL,
                no_measure_applied INTEGER   NOT NULL DEFAULT 0,
                no_measure_reason  CLOB      DEFAULT NULL,
                effective_from     DATE      DEFAULT NULL,
                effective_to       DATE      DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_sanction_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_sanction_student       FOREIGN KEY (student_id)       REFERENCES student (id),
                CONSTRAINT fk_sanction_group         FOREIGN KEY (group_id)         REFERENCES "group" (id),
                CONSTRAINT fk_sanction_teacher       FOREIGN KEY (registered_by_id) REFERENCES teacher (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_sanction_academic_year ON sanction (academic_year_id)');
        $this->addSql('CREATE INDEX idx_sanction_student       ON sanction (student_id)');
        $this->addSql('CREATE INDEX idx_sanction_group         ON sanction (group_id)');
        $this->addSql('CREATE INDEX idx_sanction_teacher       ON sanction (registered_by_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_sanction_measure (
                sanction_id         CHAR(36) NOT NULL,
                sanction_measure_id CHAR(36) NOT NULL,
                PRIMARY KEY (sanction_id, sanction_measure_id),
                CONSTRAINT fk_ssm_sanction FOREIGN KEY (sanction_id)         REFERENCES sanction (id) ON DELETE CASCADE,
                CONSTRAINT fk_ssm_measure  FOREIGN KEY (sanction_measure_id) REFERENCES sanction_measure (id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('ALTER TABLE incident_report ADD COLUMN sanction_id CHAR(36) DEFAULT NULL REFERENCES sanction (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_ir_sanction ON incident_report (sanction_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP INDEX idx_ir_sanction');
        $this->addSql('DROP TABLE sanction_sanction_measure');
        $this->addSql('DROP TABLE sanction');
        $this->addSql('DROP TABLE sanction_measure');
        $this->addSql('DROP TABLE sanction_measure_category');
    }
}
