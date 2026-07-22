<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea non_working_day para los días no lectivos del curso académico (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE non_working_day (
                id                UUID         NOT NULL,
                academic_year_id  UUID         NOT NULL,
                date              DATE         NOT NULL,
                description       VARCHAR(255) NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_nwd_year ON non_working_day (academic_year_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_nwd_year_date ON non_working_day (academic_year_id, date)');
        $this->addSql('ALTER TABLE non_working_day ADD CONSTRAINT FK_nwd_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE non_working_day DROP CONSTRAINT FK_nwd_year');
        $this->addSql('DROP TABLE non_working_day');
    }
}
