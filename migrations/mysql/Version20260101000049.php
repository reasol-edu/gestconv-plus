<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sin cambios en MySQL / MariaDB: la conversión de ids de setting_definition a UUID binario solo hace falta en SQLite (MySQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        // MySQL siembra los ids con UNHEX(REPLACE(UUID(), '-', '')), que ya son BINARY(16). Se
        // conserva para que las tres carpetas compartan los mismos números de versión.
        $this->addSql('SELECT 1');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql('SELECT 1');
    }
}
