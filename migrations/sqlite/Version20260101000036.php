<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000036 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade daily_note_type y daily_note para las notas diarias de aviso, y siembra los tipos por defecto en centros existentes (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE daily_note_type (
                id                      CHAR(36)     NOT NULL,
                educational_centre_id   CHAR(36)     NOT NULL,
                name                    VARCHAR(200) NOT NULL,
                occurrences_for_report  INTEGER      NOT NULL DEFAULT 0,
                position                INTEGER      NOT NULL DEFAULT 0,
                active                  INTEGER      NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_dnt_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_daily_note_type_centre ON daily_note_type (educational_centre_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE daily_note (
                id                CHAR(36) NOT NULL,
                academic_year_id  CHAR(36) NOT NULL,
                student_id        CHAR(36) NOT NULL,
                group_id          CHAR(36) NOT NULL,
                type_id           CHAR(36) NOT NULL,
                registered_by_id  CHAR(36) NOT NULL,
                occurred_at       DATETIME NOT NULL,
                created_at        DATETIME NOT NULL,
                observations      CLOB     DEFAULT NULL,
                ignored           INTEGER  NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                CONSTRAINT fk_dn_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_dn_student       FOREIGN KEY (student_id)       REFERENCES student (id),
                CONSTRAINT fk_dn_group         FOREIGN KEY (group_id)         REFERENCES "group" (id),
                CONSTRAINT fk_dn_type          FOREIGN KEY (type_id)          REFERENCES daily_note_type (id),
                CONSTRAINT fk_dn_teacher       FOREIGN KEY (registered_by_id) REFERENCES teacher (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_daily_note_academic_year ON daily_note (academic_year_id)');
        $this->addSql('CREATE INDEX idx_daily_note_student       ON daily_note (student_id)');
        $this->addSql('CREATE INDEX idx_daily_note_group         ON daily_note (group_id)');
        $this->addSql('CREATE INDEX idx_daily_note_type          ON daily_note (type_id)');
        $this->addSql('CREATE INDEX idx_daily_note_teacher       ON daily_note (registered_by_id)');
        $this->addSql('CREATE INDEX idx_daily_note_occurred      ON daily_note (occurred_at)');

        $this->seedDefaultTypesForExistingCentres();
    }

    private function seedDefaultTypesForExistingCentres(): void
    {
        $uuid = "lower(hex(randomblob(4)) || '-' || hex(randomblob(2)) || '-4' || substr(hex(randomblob(2)),2)"
            . " || '-' || substr('89ab', abs(random()) % 4 + 1, 1) || substr(hex(randomblob(2)),2) || '-' || hex(randomblob(6)))";

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
                . 'SELECT ' . $uuid . ', id, ?, ?, ?, 1 FROM educational_centre',
                [$name, $occurrences, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE daily_note');
        $this->addSql('DROP TABLE daily_note_type');
    }
}
