<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000067 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ámbito de las categorías de ubicaciones (solo partes o todos los sitios); la categoría «General» queda solo para partes (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("ALTER TABLE location_option_category ADD COLUMN scope VARCHAR(10) NOT NULL DEFAULT 'all'");
        // La categoría por defecto, con las ubicaciones propias de un parte (en clase, recreo…), solo se ofrece en los partes.
        $this->addSql("UPDATE location_option_category SET scope = 'reports' WHERE name = 'General'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE location_option_category DROP COLUMN scope');
    }
}
