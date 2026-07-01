<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade tablas de sanciones con medidas disciplinarias (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_measure_category (
                id                    UUID         NOT NULL,
                educational_centre_id UUID         NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                position              INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_smc_centre ON sanction_measure_category (educational_centre_id)');
        $this->addSql('ALTER TABLE sanction_measure_category ADD CONSTRAINT fk_smc_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_measure (
                id                    UUID         NOT NULL,
                educational_centre_id UUID         NOT NULL,
                category_id           UUID         NOT NULL,
                name                  VARCHAR(500) NOT NULL,
                has_date_range        BOOLEAN      NOT NULL DEFAULT FALSE,
                position              INT          NOT NULL DEFAULT 0,
                active                BOOLEAN      NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_sm_centre   ON sanction_measure (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_sm_category ON sanction_measure (category_id)');
        $this->addSql('ALTER TABLE sanction_measure ADD CONSTRAINT fk_sm_centre   FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sanction_measure ADD CONSTRAINT fk_sm_category FOREIGN KEY (category_id)           REFERENCES sanction_measure_category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction (
                id                 UUID         NOT NULL,
                academic_year_id   UUID         NOT NULL,
                student_id         UUID         NOT NULL,
                group_id           UUID         NOT NULL,
                registered_by_id   UUID         NOT NULL,
                created_at         TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                details            TEXT         NOT NULL,
                no_measure_applied BOOLEAN      NOT NULL DEFAULT FALSE,
                no_measure_reason  TEXT         DEFAULT NULL,
                effective_from     DATE         DEFAULT NULL,
                effective_to       DATE         DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN sanction.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_sanction_academic_year ON sanction (academic_year_id)');
        $this->addSql('CREATE INDEX idx_sanction_student       ON sanction (student_id)');
        $this->addSql('CREATE INDEX idx_sanction_group         ON sanction (group_id)');
        $this->addSql('CREATE INDEX idx_sanction_teacher       ON sanction (registered_by_id)');
        $this->addSql('ALTER TABLE sanction ADD CONSTRAINT fk_sanction_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sanction ADD CONSTRAINT fk_sanction_student       FOREIGN KEY (student_id)       REFERENCES student (id)       NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sanction ADD CONSTRAINT fk_sanction_group         FOREIGN KEY (group_id)         REFERENCES "group" (id)       NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sanction ADD CONSTRAINT fk_sanction_teacher       FOREIGN KEY (registered_by_id) REFERENCES teacher (id)        NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_sanction_measure (
                sanction_id         UUID NOT NULL,
                sanction_measure_id UUID NOT NULL,
                PRIMARY KEY (sanction_id, sanction_measure_id)
            )
        SQL);
        $this->addSql('ALTER TABLE sanction_sanction_measure ADD CONSTRAINT fk_ssm_sanction FOREIGN KEY (sanction_id)         REFERENCES sanction (id)         ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sanction_sanction_measure ADD CONSTRAINT fk_ssm_measure  FOREIGN KEY (sanction_measure_id) REFERENCES sanction_measure (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE incident_report ADD COLUMN sanction_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE incident_report ADD CONSTRAINT fk_ir_sanction FOREIGN KEY (sanction_id) REFERENCES sanction (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_ir_sanction ON incident_report (sanction_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE incident_report DROP CONSTRAINT fk_ir_sanction');
        $this->addSql('DROP INDEX idx_ir_sanction');
        $this->addSql('ALTER TABLE incident_report DROP COLUMN sanction_id');
        $this->addSql('DROP TABLE sanction_sanction_measure');
        $this->addSql('DROP TABLE sanction');
        $this->addSql('DROP TABLE sanction_measure');
        $this->addSql('DROP TABLE sanction_measure_category');
    }
}
