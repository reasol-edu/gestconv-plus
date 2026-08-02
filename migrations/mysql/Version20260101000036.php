<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000036 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade daily_note_type y daily_note para las notas diarias de aviso, y siembra los tipos por defecto en centros existentes (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE daily_note_type (
                id                      BINARY(16)   NOT NULL,
                educational_centre_id   BINARY(16)   NOT NULL,
                name                    VARCHAR(200) NOT NULL,
                occurrences_for_report  INT          NOT NULL DEFAULT 0,
                position                INT          NOT NULL DEFAULT 0,
                active                  TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_daily_note_type_centre (educational_centre_id),
                CONSTRAINT fk_dnt_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE daily_note (
                id                BINARY(16) NOT NULL,
                academic_year_id  BINARY(16) NOT NULL,
                student_id        BINARY(16) NOT NULL,
                group_id          BINARY(16) NOT NULL,
                type_id           BINARY(16) NOT NULL,
                registered_by_id  BINARY(16) NOT NULL,
                occurred_at       DATETIME   NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at        DATETIME   NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                observations      LONGTEXT   DEFAULT NULL,
                ignored           TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                INDEX idx_daily_note_academic_year (academic_year_id),
                INDEX idx_daily_note_student       (student_id),
                INDEX idx_daily_note_group         (group_id),
                INDEX idx_daily_note_type          (type_id),
                INDEX idx_daily_note_teacher       (registered_by_id),
                INDEX idx_daily_note_occurred      (occurred_at),
                CONSTRAINT fk_dn_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_dn_student       FOREIGN KEY (student_id)       REFERENCES student (id),
                CONSTRAINT fk_dn_group         FOREIGN KEY (group_id)         REFERENCES `group` (id),
                CONSTRAINT fk_dn_type          FOREIGN KEY (type_id)          REFERENCES daily_note_type (id),
                CONSTRAINT fk_dn_teacher       FOREIGN KEY (registered_by_id) REFERENCES teacher (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->seedDefaultTypesForExistingCentres();
    }

    private function seedDefaultTypesForExistingCentres(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/daily_note_types.yaml');
        $types  = is_array($config) && is_array($config['types'] ?? null) ? $config['types'] : [];

        foreach ($types as $position => $typeData) {
            if (!is_array($typeData) || !is_int($position)) {
                continue;
            }

            $name = is_string($typeData['name'] ?? null) ? $typeData['name'] : '';
            if ($name === '') {
                continue;
            }

            $occurrences = (int) ($typeData['occurrences_for_report'] ?? 0);

            $this->addSql(
                'INSERT INTO daily_note_type (id, educational_centre_id, name, occurrences_for_report, position, active) '
                . 'SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), id, ?, ?, ?, 1 FROM educational_centre',
                [$name, $occurrences, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('DROP TABLE daily_note');
        $this->addSql('DROP TABLE daily_note_type');
    }
}
