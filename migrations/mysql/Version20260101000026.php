<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade el catálogo de ubicaciones (dónde sucedió) para partes de convivencia (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE location_option_category (
                id                    BINARY(16)   NOT NULL,
                educational_centre_id BINARY(16)   NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                position              INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                INDEX idx_location_option_category_centre (educational_centre_id),
                CONSTRAINT fk_loc_category_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE location_option (
                id                    BINARY(16)   NOT NULL,
                educational_centre_id BINARY(16)   NOT NULL,
                category_id           BINARY(16)   NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                position              INT          NOT NULL DEFAULT 0,
                active                TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_location_option_centre   (educational_centre_id),
                INDEX idx_location_option_category (category_id),
                CONSTRAINT fk_location_option_centre   FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_location_option_category FOREIGN KEY (category_id)           REFERENCES location_option_category (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql('ALTER TABLE incident_report ADD COLUMN location_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE incident_report ADD CONSTRAINT fk_ir_location FOREIGN KEY (location_id) REFERENCES location_option (id) ON DELETE SET NULL');
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
                . 'SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), id, ?, ? FROM educational_centre',
                [$catName, $catPosition]
            );

            $options = is_array($catData['options'] ?? null) ? $catData['options'] : [];
            foreach ($options as $optPosition => $optName) {
                if (!is_string($optName) || !is_int($optPosition)) {
                    continue;
                }

                $this->addSql(
                    'INSERT INTO location_option (id, educational_centre_id, category_id, name, position, active) '
                    . 'SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), c.educational_centre_id, c.id, ?, ?, 1 '
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
            UPDATE incident_report ir
            JOIN academic_year ay ON ay.id = ir.academic_year_id
            JOIN location_option lo ON lo.educational_centre_id = ay.educational_centre_id AND lo.name = 'Otros'
            SET ir.location_id = lo.id
            WHERE ir.location_id IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql('ALTER TABLE incident_report DROP FOREIGN KEY fk_ir_location');
        $this->addSql('ALTER TABLE incident_report DROP INDEX idx_incident_report_location');
        $this->addSql('ALTER TABLE incident_report DROP COLUMN location_id');

        $this->addSql('DROP TABLE location_option');
        $this->addSql('DROP TABLE location_option_category');
    }
}
