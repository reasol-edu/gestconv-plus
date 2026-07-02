<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Yaml\Yaml;

final class Version20260101000009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla communication_method y siembra los métodos por defecto en centros existentes (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE communication_method (
                id                    UUID         NOT NULL,
                educational_centre_id UUID         NOT NULL,
                name                  VARCHAR(200) NOT NULL,
                position              INT          NOT NULL DEFAULT 0,
                active                BOOLEAN      NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_communication_method_centre ON communication_method (educational_centre_id)');
        $this->addSql('ALTER TABLE communication_method ADD CONSTRAINT fk_cm_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $config  = Yaml::parseFile(dirname(__DIR__, 2) . '/config/communication_methods.yaml');
        $methods = is_array($config) && is_array($config['methods'] ?? null) ? $config['methods'] : [];

        foreach ($methods as $position => $name) {
            if (!is_string($name) || !is_int($position)) {
                continue;
            }

            $this->addSql(
                'INSERT INTO communication_method (id, educational_centre_id, name, position, active) '
                . 'SELECT gen_random_uuid(), id, ?, ?, TRUE FROM educational_centre',
                [$name, $position]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP TABLE communication_method');
    }
}
