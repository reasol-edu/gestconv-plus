<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000036 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade daily_note_type y daily_note para las notas diarias de aviso, y siembra los tipos por defecto en centros existentes (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE daily_note_type (
                id                      UUID         NOT NULL,
                educational_centre_id   UUID         NOT NULL,
                name                    VARCHAR(200) NOT NULL,
                occurrences_for_report  INT          NOT NULL DEFAULT 0,
                expiry_days             INT          NOT NULL DEFAULT 0,
                position                INT          NOT NULL DEFAULT 0,
                active                  BOOLEAN      NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_daily_note_type_centre ON daily_note_type (educational_centre_id)');
        $this->addSql('ALTER TABLE daily_note_type ADD CONSTRAINT fk_dnt_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE daily_note (
                id                UUID    NOT NULL,
                academic_year_id  UUID    NOT NULL,
                student_id        UUID    NOT NULL,
                group_id          UUID    NOT NULL,
                type_id           UUID    NOT NULL,
                registered_by_id  UUID    NOT NULL,
                occurred_at       TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_at        TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                observations      TEXT    DEFAULT NULL,
                active            BOOLEAN NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN daily_note.occurred_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN daily_note.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_daily_note_academic_year ON daily_note (academic_year_id)');
        $this->addSql('CREATE INDEX idx_daily_note_student       ON daily_note (student_id)');
        $this->addSql('CREATE INDEX idx_daily_note_group         ON daily_note (group_id)');
        $this->addSql('CREATE INDEX idx_daily_note_type          ON daily_note (type_id)');
        $this->addSql('CREATE INDEX idx_daily_note_teacher       ON daily_note (registered_by_id)');
        $this->addSql('CREATE INDEX idx_daily_note_occurred      ON daily_note (occurred_at)');
        $this->addSql('ALTER TABLE daily_note ADD CONSTRAINT fk_dn_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE daily_note ADD CONSTRAINT fk_dn_student       FOREIGN KEY (student_id)       REFERENCES student (id)        NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE daily_note ADD CONSTRAINT fk_dn_group         FOREIGN KEY (group_id)         REFERENCES "group" (id)        NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE daily_note ADD CONSTRAINT fk_dn_type          FOREIGN KEY (type_id)          REFERENCES daily_note_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE daily_note ADD CONSTRAINT fk_dn_teacher       FOREIGN KEY (registered_by_id) REFERENCES teacher (id)        NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->seedDefaultTypesForExistingCentres();
    }

    /**
     * Siembra el catálogo por defecto (config/daily_note_types.yaml) en los centros ya
     * existentes, igual que hace DailyNoteTypeSeeder para los centros nuevos.
     */
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
            $expiryDays  = (int) ($typeData['expiry_days'] ?? 0);

            $this->addSql(
                'INSERT INTO daily_note_type (id, educational_centre_id, name, occurrences_for_report, expiry_days, position, active) '
                . 'SELECT gen_random_uuid(), id, ?, ?, ?, ?, TRUE FROM educational_centre',
                [$name, $occurrences, $expiryDays, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP TABLE daily_note');
        $this->addSql('DROP TABLE daily_note_type');
    }
}
