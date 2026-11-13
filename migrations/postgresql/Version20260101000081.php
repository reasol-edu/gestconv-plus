<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000081 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pasos de la lista de inicio de curso confirmados a mano (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE setup_step_confirmation (
                id               UUID         NOT NULL,
                academic_year_id UUID         NOT NULL,
                confirmed_by_id  UUID         DEFAULT NULL,
                step_key         VARCHAR(40)  NOT NULL,
                confirmed_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_setup_step_confirmation ON setup_step_confirmation (academic_year_id, step_key)');
        $this->addSql('CREATE INDEX idx_setup_step_teacher ON setup_step_confirmation (confirmed_by_id)');
        $this->addSql('ALTER TABLE setup_step_confirmation ADD CONSTRAINT fk_setup_step_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE setup_step_confirmation ADD CONSTRAINT fk_setup_step_teacher FOREIGN KEY (confirmed_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP TABLE setup_step_confirmation');
    }
}
