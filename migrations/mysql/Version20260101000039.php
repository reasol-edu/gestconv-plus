<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea setting_file, almacén genérico de ficheros deduplicado por hash para ajustes de tipo fichero (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE setting_file (
                id          BINARY(16)    NOT NULL,
                hash        VARCHAR(64)   NOT NULL,
                content     LONGBLOB      NOT NULL,
                mime_type   VARCHAR(100)  NOT NULL,
                size        INT           NOT NULL,
                created_at  DATETIME      NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UQ_setting_file_hash ON setting_file (hash)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('DROP TABLE setting_file');
    }
}
