<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea setting_file, almacén genérico de ficheros deduplicado por hash para ajustes de tipo fichero (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE setting_file (
                id          CHAR(36)      NOT NULL,
                hash        VARCHAR(64)   NOT NULL,
                content     BLOB          NOT NULL,
                mime_type   VARCHAR(100)  NOT NULL,
                size        INTEGER       NOT NULL,
                created_at  DATETIME      NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UQ_setting_file_hash ON setting_file (hash)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE setting_file');
    }
}
