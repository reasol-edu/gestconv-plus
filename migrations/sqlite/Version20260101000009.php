<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla communication_method y siembra los métodos por defecto en centros existentes (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE communication_method (
                id                    CHAR(36)     NOT NULL,
                educational_centre_id CHAR(36)     NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                position              INTEGER      NOT NULL DEFAULT 0,
                active                INTEGER      NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_cm_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_communication_method_centre ON communication_method (educational_centre_id)');

        $uuid = "lower(hex(randomblob(4)) || '-' || hex(randomblob(2)) || '-4' || substr(hex(randomblob(2)),2)"
            . " || '-' || substr('89ab', abs(random()) % 4 + 1, 1) || substr(hex(randomblob(2)),2) || '-' || hex(randomblob(6)))";

        $config  = Yaml::parseFile(dirname(__DIR__, 2) . '/config/communication_methods.yaml');
        $methods = is_array($config) && is_array($config['methods'] ?? null) ? $config['methods'] : [];

        foreach ($methods as $position => $name) {
            if (!is_string($name) || !is_int($position)) {
                continue;
            }

            $this->addSql(
                'INSERT INTO communication_method (id, educational_centre_id, name, position, active) '
                . 'SELECT ' . $uuid . ', id, ?, ?, 1 FROM educational_centre',
                [$name, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE communication_method');
    }
}
