<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade correo electrónico de los tutores al estudiante (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE student
                ADD COLUMN tutor_email1 VARCHAR(180) DEFAULT NULL,
                ADD COLUMN tutor_email2 VARCHAR(180) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE student
                DROP COLUMN tutor_email1,
                DROP COLUMN tutor_email2
        SQL);
    }
}
