<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000081 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasos de la lista de inicio de curso confirmados a mano (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE setup_step_confirmation (
                id               CHAR(36)    NOT NULL,
                academic_year_id CHAR(36)    NOT NULL,
                confirmed_by_id  CHAR(36)    DEFAULT NULL,
                step_key         VARCHAR(40) NOT NULL,
                confirmed_at     DATETIME    NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_setup_step_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE,
                CONSTRAINT fk_setup_step_teacher FOREIGN KEY (confirmed_by_id) REFERENCES teacher (id) ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_setup_step_confirmation ON setup_step_confirmation (academic_year_id, step_key)');
        $this->addSql('CREATE INDEX idx_setup_step_teacher ON setup_step_confirmation (confirmed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE setup_step_confirmation');
    }
}
