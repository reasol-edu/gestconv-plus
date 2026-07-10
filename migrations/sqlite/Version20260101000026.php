<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade el catálogo de ubicaciones (dónde sucedió) para partes de convivencia (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE location_option_category (
                id                    CHAR(36)     NOT NULL,
                educational_centre_id CHAR(36)     NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                position              INTEGER      NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                CONSTRAINT fk_loc_category_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_location_option_category_centre ON location_option_category (educational_centre_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE location_option (
                id                    CHAR(36)     NOT NULL,
                educational_centre_id CHAR(36)     NOT NULL,
                category_id           CHAR(36)     NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                position              INTEGER      NOT NULL DEFAULT 0,
                active                INTEGER      NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_location_option_centre   FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_location_option_category FOREIGN KEY (category_id)           REFERENCES location_option_category (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_location_option_centre   ON location_option (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_location_option_category ON location_option (category_id)');

        $this->addSql('ALTER TABLE incident_report ADD COLUMN location_id CHAR(36) DEFAULT NULL REFERENCES location_option (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_incident_report_location ON incident_report (location_id)');

        $this->seedDefaultLocationsForExistingCentres();
        $this->backfillReportsWithoutLocation();
    }

    /**
     * Siembra el catálogo por defecto (config/location_options.yaml) en los centros ya existentes,
     * igual que hace LocationOptionSeeder para los centros nuevos.
     */
    private function seedDefaultLocationsForExistingCentres(): void
    {
        $uuid = "lower(hex(randomblob(4)) || '-' || hex(randomblob(2)) || '-4' || substr(hex(randomblob(2)),2)"
            . " || '-' || substr('89ab', abs(random()) % 4 + 1, 1) || substr(hex(randomblob(2)),2) || '-' || hex(randomblob(6)))";

        $config     = Yaml::parseFile(dirname(__DIR__, 2) . '/config/location_options.yaml');
        $categories = is_array($config) && is_array($config['categories'] ?? null) ? $config['categories'] : [];

        foreach ($categories as $catPosition => $catData) {
            if (!is_array($catData) || !is_int($catPosition)) {
                continue;
            }

            $catName = is_string($catData['name'] ?? null) ? $catData['name'] : '';
            if ($catName === '') {
                continue;
            }

            $this->addSql(
                'INSERT INTO location_option_category (id, educational_centre_id, name, position) '
                . 'SELECT ' . $uuid . ', id, ?, ? FROM educational_centre',
                [$catName, $catPosition]
            );

            $options = is_array($catData['options'] ?? null) ? $catData['options'] : [];
            foreach ($options as $optPosition => $optName) {
                if (!is_string($optName) || !is_int($optPosition)) {
                    continue;
                }

                $this->addSql(
                    'INSERT INTO location_option (id, educational_centre_id, category_id, name, position, active) '
                    . 'SELECT ' . $uuid . ', c.educational_centre_id, c.id, ?, ?, 1 '
                    . 'FROM location_option_category c WHERE c.name = ?',
                    [$optName, $optPosition, $catName]
                );
            }
        }
    }

    /**
     * Los partes creados antes de esta migración no tenían ubicación: se les asigna "Otros" por centro.
     */
    private function backfillReportsWithoutLocation(): void
    {
        $this->addSql(<<<'SQL'
            UPDATE incident_report
            SET location_id = (
                SELECT lo.id
                FROM location_option lo
                JOIN academic_year ay ON ay.educational_centre_id = lo.educational_centre_id
                WHERE ay.id = incident_report.academic_year_id AND lo.name = 'Otros'
            )
            WHERE location_id IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP INDEX idx_incident_report_location');
        $this->addSql('DROP TABLE location_option');
        $this->addSql('DROP TABLE location_option_category');
    }
}
