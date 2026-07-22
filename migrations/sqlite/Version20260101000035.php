<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea non_working_day para los días no lectivos del curso académico (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE non_working_day (
                id                CHAR(36)     NOT NULL,
                academic_year_id  CHAR(36)     NOT NULL,
                date              DATE         NOT NULL,
                description       VARCHAR(255) NULL,
                PRIMARY KEY (id),
                CONSTRAINT FK_nwd_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_nwd_year ON non_working_day (academic_year_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_nwd_year_date ON non_working_day (academic_year_id, date)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE non_working_day');
    }
}
