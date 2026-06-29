<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade correo electrónico de los tutores al estudiante (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE student
                ADD tutor_email1 VARCHAR(180) DEFAULT NULL AFTER tutor_name1,
                ADD tutor_email2 VARCHAR(180) DEFAULT NULL AFTER tutor_name2
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE student
                DROP COLUMN tutor_email1,
                DROP COLUMN tutor_email2
        SQL);
    }
}
