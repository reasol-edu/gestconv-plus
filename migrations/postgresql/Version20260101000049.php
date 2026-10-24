<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sin cambios en PostgreSQL: la normalización de los ids sembrados por migraciones solo hace falta en SQLite (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        // PostgreSQL tiene un tipo uuid nativo: los ids sembrados siempre fueron UUID reales. Se
        // conserva para que las tres carpetas compartan los mismos números de versión.
        $this->addSql('SELECT 1');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('SELECT 1');
    }
}
