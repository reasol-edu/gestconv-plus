<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000067 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ámbito de las categorías de ubicaciones (solo partes o todos los sitios); la categoría «General» queda solo para partes (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("ALTER TABLE location_option_category ADD scope VARCHAR(10) DEFAULT 'all' NOT NULL");
        // La categoría por defecto, con las ubicaciones propias de un parte (en clase, recreo…), solo se ofrece en los partes.
        $this->addSql("UPDATE location_option_category SET scope = 'reports' WHERE name = 'General'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE location_option_category DROP scope');
    }
}
