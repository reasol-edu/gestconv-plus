<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade exit_reason, student_exit y student_priority_pass para las salidas autorizadas del aula, y siembra los motivos por defecto en centros existentes (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE exit_reason (
                id                      UUID         NOT NULL,
                educational_centre_id   UUID         NOT NULL,
                name                    VARCHAR(200) NOT NULL,
                position                INT          NOT NULL DEFAULT 0,
                active                  BOOLEAN      NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_exit_reason_centre ON exit_reason (educational_centre_id)');
        $this->addSql('ALTER TABLE exit_reason ADD CONSTRAINT fk_exit_reason_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE student_exit (
                id                UUID    NOT NULL,
                academic_year_id  UUID    NOT NULL,
                student_id        UUID    NOT NULL,
                group_id          UUID    NOT NULL,
                reason_id         UUID    NOT NULL,
                observations      TEXT    DEFAULT NULL,
                exited_at         TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                authorized_by_id  UUID    NOT NULL,
                returned_at       TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                returned_by_id    UUID    DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN student_exit.exited_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN student_exit.returned_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_student_exit_year_returned ON student_exit (academic_year_id, returned_at)');
        $this->addSql('CREATE INDEX idx_student_exit_exited        ON student_exit (exited_at)');
        $this->addSql('CREATE INDEX idx_student_exit_student       ON student_exit (student_id)');
        $this->addSql('CREATE INDEX idx_student_exit_group         ON student_exit (group_id)');
        $this->addSql('CREATE INDEX idx_student_exit_reason        ON student_exit (reason_id)');
        $this->addSql('CREATE INDEX idx_student_exit_authorized_by ON student_exit (authorized_by_id)');
        $this->addSql('CREATE INDEX idx_student_exit_returned_by   ON student_exit (returned_by_id)');
        $this->addSql('ALTER TABLE student_exit ADD CONSTRAINT fk_se_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE student_exit ADD CONSTRAINT fk_se_student       FOREIGN KEY (student_id)       REFERENCES student (id)       NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE student_exit ADD CONSTRAINT fk_se_group         FOREIGN KEY (group_id)         REFERENCES "group" (id)       NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE student_exit ADD CONSTRAINT fk_se_reason        FOREIGN KEY (reason_id)        REFERENCES exit_reason (id)   NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE student_exit ADD CONSTRAINT fk_se_authorized_by FOREIGN KEY (authorized_by_id) REFERENCES teacher (id)       NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE student_exit ADD CONSTRAINT fk_se_returned_by   FOREIGN KEY (returned_by_id)   REFERENCES teacher (id)       NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE student_priority_pass (
                id                UUID    NOT NULL,
                student_id        UUID    NOT NULL,
                academic_year_id  UUID    NOT NULL,
                description       TEXT    NOT NULL,
                granted_by_id     UUID    DEFAULT NULL,
                granted_at        TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN student_priority_pass.granted_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE UNIQUE INDEX uq_priority_pass_student_year ON student_priority_pass (student_id, academic_year_id)');
        $this->addSql('CREATE INDEX idx_priority_pass_year       ON student_priority_pass (academic_year_id)');
        $this->addSql('CREATE INDEX idx_priority_pass_granted_by ON student_priority_pass (granted_by_id)');
        $this->addSql('ALTER TABLE student_priority_pass ADD CONSTRAINT fk_spp_student       FOREIGN KEY (student_id)       REFERENCES student (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE student_priority_pass ADD CONSTRAINT fk_spp_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE student_priority_pass ADD CONSTRAINT fk_spp_granted_by    FOREIGN KEY (granted_by_id)    REFERENCES teacher (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->seedDefaultReasonsForExistingCentres();
    }

    /**
     * Siembra el catálogo por defecto (config/exit_reasons.yaml) en los centros ya existentes,
     * igual que hace ExitReasonSeeder para los centros nuevos.
     */
    private function seedDefaultReasonsForExistingCentres(): void
    {
        $config  = Yaml::parseFile(dirname(__DIR__, 2) . '/config/exit_reasons.yaml');
        $reasons = is_array($config) && is_array($config['reasons'] ?? null) ? $config['reasons'] : [];

        foreach ($reasons as $position => $name) {
            if (!is_string($name) || $name === '' || !is_int($position)) {
                continue;
            }

            $this->addSql(
                'INSERT INTO exit_reason (id, educational_centre_id, name, position, active) '
                . 'SELECT gen_random_uuid(), id, ?, ?, TRUE FROM educational_centre',
                [$name, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP TABLE student_priority_pass');
        $this->addSql('DROP TABLE student_exit');
        $this->addSql('DROP TABLE exit_reason');
    }
}
