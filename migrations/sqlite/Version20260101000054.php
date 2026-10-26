<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Actividades de centro: catálogo school_event_type, tipo, departamento, lugar, coordinador y acompañantes en school_event (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE school_event_type (
                id                      CHAR(36)     NOT NULL,
                educational_centre_id   CHAR(36)     NOT NULL,
                name                    VARCHAR(200) NOT NULL,
                color                   VARCHAR(7)   DEFAULT NULL,
                position                INTEGER      NOT NULL DEFAULT 0,
                active                  INTEGER      NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_school_event_type_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_school_event_type_centre ON school_event_type (educational_centre_id)');

        $this->seedDefaultTypesForExistingCentres();

        $this->addSql('ALTER TABLE school_event ADD COLUMN type_id CHAR(36) DEFAULT NULL REFERENCES school_event_type (id)');
        $this->addSql('ALTER TABLE school_event ADD COLUMN organizing_department VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE school_event ADD COLUMN location VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE school_event ADD COLUMN coordinator_id CHAR(36) DEFAULT NULL REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_school_event_type ON school_event (type_id)');
        $this->addSql('CREATE INDEX IDX_school_event_coordinator ON school_event (coordinator_id)');

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
                school_event_id  CHAR(36) NOT NULL,
                teacher_id       CHAR(36) NOT NULL,
                PRIMARY KEY(school_event_id, teacher_id),
                CONSTRAINT FK_sec_school_event FOREIGN KEY (school_event_id) REFERENCES school_event(id) ON DELETE CASCADE,
                CONSTRAINT FK_sec_teacher      FOREIGN KEY (teacher_id)      REFERENCES teacher(id)      ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_sec_school_event ON school_event_companion (school_event_id)');
        $this->addSql('CREATE INDEX IDX_sec_teacher      ON school_event_companion (teacher_id)');
    }

    /**
     * Siembra el catálogo por defecto (config/school_event_types.yaml) en los centros ya existentes, igual que
     * hace SchoolEventTypeSeeder con los nuevos. Los ids se generan aquí, en PHP, y se envían como cadena,
     * que es el formato con el que Doctrine los guarda y los busca en SQLite (ver skills/database.md).
     */
    private function seedDefaultTypesForExistingCentres(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/school_event_types.yaml');
        $types  = is_array($config) && is_array($config['types'] ?? null) ? $config['types'] : [];

        $centreIds = $this->connection->fetchFirstColumn('SELECT id FROM educational_centre');

        foreach ($types as $position => $type) {
            if (!is_array($type) || !is_string($type['name'] ?? null) || $type['name'] === '' || !is_int($position)) {
                continue;
            }

            foreach ($centreIds as $centreId) {
                $this->addSql(
                    'INSERT INTO school_event_type (id, educational_centre_id, name, color, position, active) VALUES (?, ?, ?, ?, ?, 1)',
                    [Uuid::v7()->toBinary(), $centreId, $type['name'], is_string($type['color'] ?? null) ? $type['color'] : null, $position]
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

        $this->addSql('DROP TABLE school_event_companion');

        $this->addSql('DROP INDEX IDX_school_event_type');
        $this->addSql('DROP INDEX IDX_school_event_coordinator');
        $this->addSql('ALTER TABLE school_event DROP COLUMN type_id');
        $this->addSql('ALTER TABLE school_event DROP COLUMN organizing_department');
        $this->addSql('ALTER TABLE school_event DROP COLUMN location');
        $this->addSql('ALTER TABLE school_event DROP COLUMN coordinator_id');

        $this->addSql('DROP TABLE school_event_type');
    }
}
