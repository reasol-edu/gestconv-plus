<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea non_working_day para los días no lectivos del curso académico (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE non_working_day (
                id                BINARY(16)   NOT NULL,
                academic_year_id  BINARY(16)   NOT NULL,
                date              DATE         NOT NULL,
                description       VARCHAR(255) NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_nwd_year ON non_working_day (academic_year_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_nwd_year_date ON non_working_day (academic_year_id, date)');
        $this->addSql('ALTER TABLE non_working_day ADD CONSTRAINT FK_nwd_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE non_working_day DROP FOREIGN KEY FK_nwd_year');
        $this->addSql('DROP TABLE non_working_day');
    }
}
