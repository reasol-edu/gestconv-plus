<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Actividades de centro: catálogo school_event_type, tipo, departamento, lugar, coordinador y acompañantes en school_event (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event_type (
                id                      UUID         NOT NULL,
                educational_centre_id   UUID         NOT NULL,
                name                    VARCHAR(200) NOT NULL,
                color                   VARCHAR(7)   DEFAULT NULL,
                position                INT          NOT NULL DEFAULT 0,
                active                  BOOLEAN      NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_school_event_type_centre ON school_event_type (educational_centre_id)');
        $this->addSql('ALTER TABLE school_event_type ADD CONSTRAINT fk_school_event_type_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->seedDefaultTypesForExistingCentres();

        $this->addSql('ALTER TABLE school_event ADD type_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE school_event ADD organizing_department VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE school_event ADD location VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE school_event ADD coordinator_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_school_event_type ON school_event (type_id)');
        $this->addSql('CREATE INDEX IDX_school_event_coordinator ON school_event (coordinator_id)');
        $this->addSql('ALTER TABLE school_event ADD CONSTRAINT FK_school_event_type FOREIGN KEY (type_id) REFERENCES school_event_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE school_event ADD CONSTRAINT FK_school_event_coordinator FOREIGN KEY (coordinator_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Las actividades que ya existían pasan a ser del primer tipo del catálogo de su centro («Complementaria»).
        $this->addSql(<<<'SQL'
            UPDATE school_event SET type_id = (
                SELECT t.id FROM school_event_type t
                JOIN academic_year y ON y.educational_centre_id = t.educational_centre_id
                WHERE y.id = school_event.academic_year_id AND t.position = 0
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event_companion (
                school_event_id  UUID NOT NULL,
                teacher_id       UUID NOT NULL,
                PRIMARY KEY(school_event_id, teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_sec_school_event ON school_event_companion (school_event_id)');
        $this->addSql('CREATE INDEX IDX_sec_teacher      ON school_event_companion (teacher_id)');
        $this->addSql('ALTER TABLE school_event_companion ADD CONSTRAINT FK_sec_school_event FOREIGN KEY (school_event_id) REFERENCES school_event(id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE school_event_companion ADD CONSTRAINT FK_sec_teacher      FOREIGN KEY (teacher_id)      REFERENCES teacher(id)      ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    /** Siembra el catálogo por defecto (config/school_event_types.yaml) en los centros ya existentes. */
    private function seedDefaultTypesForExistingCentres(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/school_event_types.yaml');
        $types  = is_array($config) && is_array($config['types'] ?? null) ? $config['types'] : [];

        foreach ($types as $position => $type) {
            if (!is_array($type) || !is_string($type['name'] ?? null) || $type['name'] === '' || !is_int($position)) {
                continue;
            }

            $this->addSql(
                'INSERT INTO school_event_type (id, educational_centre_id, name, color, position, active) '
                . 'SELECT gen_random_uuid(), id, ?, ?, ?, TRUE FROM educational_centre',
                [$type['name'], is_string($type['color'] ?? null) ? $type['color'] : null, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE school_event_companion DROP CONSTRAINT FK_sec_school_event');
        $this->addSql('ALTER TABLE school_event_companion DROP CONSTRAINT FK_sec_teacher');
        $this->addSql('DROP TABLE school_event_companion');

        $this->addSql('ALTER TABLE school_event DROP CONSTRAINT FK_school_event_type');
        $this->addSql('ALTER TABLE school_event DROP CONSTRAINT FK_school_event_coordinator');
        $this->addSql('DROP INDEX IDX_school_event_type');
        $this->addSql('DROP INDEX IDX_school_event_coordinator');
        $this->addSql('ALTER TABLE school_event DROP COLUMN type_id');
        $this->addSql('ALTER TABLE school_event DROP COLUMN organizing_department');
        $this->addSql('ALTER TABLE school_event DROP COLUMN location');
        $this->addSql('ALTER TABLE school_event DROP COLUMN coordinator_id');

        $this->addSql('DROP TABLE school_event_type');
    }
}
