<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade exit_reason, student_exit y student_priority_pass para las salidas autorizadas del aula, y siembra los motivos por defecto en centros existentes (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE exit_reason (
                id                      CHAR(36)     NOT NULL,
                educational_centre_id   CHAR(36)     NOT NULL,
                name                    VARCHAR(200) NOT NULL,
                position                INTEGER      NOT NULL DEFAULT 0,
                active                  INTEGER      NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_exit_reason_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_exit_reason_centre ON exit_reason (educational_centre_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE student_exit (
                id                CHAR(36) NOT NULL,
                academic_year_id  CHAR(36) NOT NULL,
                student_id        CHAR(36) NOT NULL,
                group_id          CHAR(36) NOT NULL,
                reason_id         CHAR(36) NOT NULL,
                observations      CLOB     DEFAULT NULL,
                exited_at         DATETIME NOT NULL,
                authorized_by_id  CHAR(36) NOT NULL,
                returned_at       DATETIME DEFAULT NULL,
                returned_by_id    CHAR(36) DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_se_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_se_student       FOREIGN KEY (student_id)       REFERENCES student (id),
                CONSTRAINT fk_se_group         FOREIGN KEY (group_id)         REFERENCES "group" (id),
                CONSTRAINT fk_se_reason        FOREIGN KEY (reason_id)        REFERENCES exit_reason (id),
                CONSTRAINT fk_se_authorized_by FOREIGN KEY (authorized_by_id) REFERENCES teacher (id),
                CONSTRAINT fk_se_returned_by   FOREIGN KEY (returned_by_id)   REFERENCES teacher (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_student_exit_year_returned ON student_exit (academic_year_id, returned_at)');
        $this->addSql('CREATE INDEX idx_student_exit_exited        ON student_exit (exited_at)');
        $this->addSql('CREATE INDEX idx_student_exit_student       ON student_exit (student_id)');
        $this->addSql('CREATE INDEX idx_student_exit_group         ON student_exit (group_id)');
        $this->addSql('CREATE INDEX idx_student_exit_reason        ON student_exit (reason_id)');
        $this->addSql('CREATE INDEX idx_student_exit_authorized_by ON student_exit (authorized_by_id)');
        $this->addSql('CREATE INDEX idx_student_exit_returned_by   ON student_exit (returned_by_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE student_priority_pass (
                id                CHAR(36) NOT NULL,
                student_id        CHAR(36) NOT NULL,
                academic_year_id  CHAR(36) NOT NULL,
                description       CLOB     NOT NULL,
                granted_by_id     CHAR(36) DEFAULT NULL,
                granted_at        DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_spp_student       FOREIGN KEY (student_id)       REFERENCES student (id) ON DELETE CASCADE,
                CONSTRAINT fk_spp_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_spp_granted_by    FOREIGN KEY (granted_by_id)    REFERENCES teacher (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_priority_pass_student_year ON student_priority_pass (student_id, academic_year_id)');
        $this->addSql('CREATE INDEX idx_priority_pass_year       ON student_priority_pass (academic_year_id)');
        $this->addSql('CREATE INDEX idx_priority_pass_granted_by ON student_priority_pass (granted_by_id)');

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

        // Doctrine guarda los UUID en SQLite como un TEXTO con los 16 bytes (la columna se declara CHAR(36)):
        // los envía como cadena, no como blob, y los busca con ese mismo valor, así que ni un id de texto
        // de 36 caracteres ni un blob se encontrarían después, ni coincidirían con la clave foránea del
        // centro. Por eso los ids se generan aquí, en PHP, y se envían igual que Doctrine: como cadena.
        $centreIds = $this->connection->fetchFirstColumn('SELECT id FROM educational_centre');

        foreach ($reasons as $position => $name) {
            if (!is_string($name) || $name === '' || !is_int($position)) {
                continue;
            }

            foreach ($centreIds as $centreId) {
                $this->addSql(
                    'INSERT INTO exit_reason (id, educational_centre_id, name, position, active) VALUES (?, ?, ?, ?, 1)',
                    [Uuid::v7()->toBinary(), $centreId, $name, $position],
                    [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE student_priority_pass');
        $this->addSql('DROP TABLE student_exit');
        $this->addSql('DROP TABLE exit_reason');
    }
}
