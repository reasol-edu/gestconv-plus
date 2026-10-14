<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade exit_reason, student_exit y student_priority_pass para las salidas autorizadas del aula, y siembra los motivos por defecto en centros existentes (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE exit_reason (
                id                      BINARY(16)   NOT NULL,
                educational_centre_id   BINARY(16)   NOT NULL,
                name                    VARCHAR(200) NOT NULL,
                position                INT          NOT NULL DEFAULT 0,
                active                  TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_exit_reason_centre (educational_centre_id),
                CONSTRAINT fk_exit_reason_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE student_exit (
                id                BINARY(16) NOT NULL,
                academic_year_id  BINARY(16) NOT NULL,
                student_id        BINARY(16) NOT NULL,
                group_id          BINARY(16) NOT NULL,
                reason_id         BINARY(16) NOT NULL,
                observations      LONGTEXT   DEFAULT NULL,
                exited_at         DATETIME   NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                authorized_by_id  BINARY(16) NOT NULL,
                returned_at       DATETIME   DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                returned_by_id    BINARY(16) DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_student_exit_year_returned (academic_year_id, returned_at),
                INDEX idx_student_exit_exited        (exited_at),
                INDEX idx_student_exit_student       (student_id),
                INDEX idx_student_exit_group         (group_id),
                INDEX idx_student_exit_reason        (reason_id),
                INDEX idx_student_exit_authorized_by (authorized_by_id),
                INDEX idx_student_exit_returned_by   (returned_by_id),
                CONSTRAINT fk_se_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_se_student       FOREIGN KEY (student_id)       REFERENCES student (id),
                CONSTRAINT fk_se_group         FOREIGN KEY (group_id)         REFERENCES `group` (id),
                CONSTRAINT fk_se_reason        FOREIGN KEY (reason_id)        REFERENCES exit_reason (id),
                CONSTRAINT fk_se_authorized_by FOREIGN KEY (authorized_by_id) REFERENCES teacher (id),
                CONSTRAINT fk_se_returned_by   FOREIGN KEY (returned_by_id)   REFERENCES teacher (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE student_priority_pass (
                id                BINARY(16) NOT NULL,
                student_id        BINARY(16) NOT NULL,
                academic_year_id  BINARY(16) NOT NULL,
                description       LONGTEXT   NOT NULL,
                granted_by_id     BINARY(16) DEFAULT NULL,
                granted_at        DATETIME   NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE INDEX uq_priority_pass_student_year (student_id, academic_year_id),
                INDEX idx_priority_pass_year       (academic_year_id),
                INDEX idx_priority_pass_granted_by (granted_by_id),
                CONSTRAINT fk_spp_student       FOREIGN KEY (student_id)       REFERENCES student (id) ON DELETE CASCADE,
                CONSTRAINT fk_spp_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_spp_granted_by    FOREIGN KEY (granted_by_id)    REFERENCES teacher (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

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
                . 'SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), id, ?, ?, 1 FROM educational_centre',
                [$name, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('DROP TABLE student_priority_pass');
        $this->addSql('DROP TABLE student_exit');
        $this->addSql('DROP TABLE exit_reason');
    }
}
