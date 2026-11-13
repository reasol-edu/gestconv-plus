<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000081 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasos de la lista de inicio de curso confirmados a mano (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE setup_step_confirmation (
                id               BINARY(16)  NOT NULL,
                academic_year_id BINARY(16)  NOT NULL,
                confirmed_by_id  BINARY(16)  DEFAULT NULL,
                step_key         VARCHAR(40) NOT NULL,
                confirmed_at     DATETIME    NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uq_setup_step_confirmation (academic_year_id, step_key),
                INDEX idx_setup_step_teacher (confirmed_by_id),
                CONSTRAINT fk_setup_step_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE,
                CONSTRAINT fk_setup_step_teacher FOREIGN KEY (confirmed_by_id) REFERENCES teacher (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('DROP TABLE setup_step_confirmation');
    }
}
