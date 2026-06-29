<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade correo electrónico de los tutores al estudiante (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE student ADD COLUMN tutor_email1 VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE student ADD COLUMN tutor_email2 VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE student DROP COLUMN tutor_email1');
        $this->addSql('ALTER TABLE student DROP COLUMN tutor_email2');
    }
}
