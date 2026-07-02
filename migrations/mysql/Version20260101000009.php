<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla communication_method y siembra los métodos por defecto en centros existentes (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE communication_method (
                id                    BINARY(16)   NOT NULL,
                educational_centre_id BINARY(16)   NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                position              INT          NOT NULL DEFAULT 0,
                active                TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_communication_method_centre (educational_centre_id),
                CONSTRAINT fk_cm_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $config  = Yaml::parseFile(dirname(__DIR__, 2) . '/config/communication_methods.yaml');
        $methods = is_array($config) && is_array($config['methods'] ?? null) ? $config['methods'] : [];

        foreach ($methods as $position => $name) {
            if (!is_string($name) || !is_int($position)) {
                continue;
            }

            $this->addSql(
                'INSERT INTO communication_method (id, educational_centre_id, name, position, active) '
                . 'SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), id, ?, ?, 1 FROM educational_centre',
                [$name, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql('DROP TABLE communication_method');
    }
}
