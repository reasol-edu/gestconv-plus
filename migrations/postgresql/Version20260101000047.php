<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000047 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enlace público de tareas de sanción: columna share_code en sanction (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE sanction ADD share_code VARCHAR(10) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_sanction_share_code ON sanction (share_code)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP INDEX uq_sanction_share_code');
        $this->addSql('ALTER TABLE sanction DROP share_code');
    }
}
