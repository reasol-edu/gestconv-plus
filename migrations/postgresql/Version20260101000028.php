<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000028 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade campos de seguimiento a sanction y la tabla sanction_observation (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE sanction ADD COLUMN measures_effective    BOOLEAN      NULL');
        $this->addSql('ALTER TABLE sanction ADD COLUMN family_claimed        BOOLEAN      NULL');
        $this->addSql('ALTER TABLE sanction ADD COLUMN family_claim_attitude TEXT         NULL');
        $this->addSql('ALTER TABLE sanction ADD COLUMN registered_in_seneca  BOOLEAN      NULL');
        $this->addSql('ALTER TABLE sanction ADD COLUMN calendar_label        VARCHAR(255) NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_observation (
                id               UUID         NOT NULL,
                sanction_id      UUID         NOT NULL,
                registered_by_id UUID         NOT NULL,
                registered_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                text             TEXT         NOT NULL,
                created_at       TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN sanction_observation.registered_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN sanction_observation.created_at    IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_so_sanction      ON sanction_observation (sanction_id)');
        $this->addSql('CREATE INDEX idx_so_registered_by ON sanction_observation (registered_by_id)');
        $this->addSql('ALTER TABLE sanction_observation ADD CONSTRAINT fk_so_sanction      FOREIGN KEY (sanction_id)      REFERENCES sanction (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sanction_observation ADD CONSTRAINT fk_so_registered_by FOREIGN KEY (registered_by_id) REFERENCES teacher (id)                   NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP TABLE sanction_observation');
        $this->addSql('ALTER TABLE sanction DROP COLUMN measures_effective');
        $this->addSql('ALTER TABLE sanction DROP COLUMN family_claimed');
        $this->addSql('ALTER TABLE sanction DROP COLUMN family_claim_attitude');
        $this->addSql('ALTER TABLE sanction DROP COLUMN registered_in_seneca');
        $this->addSql('ALTER TABLE sanction DROP COLUMN calendar_label');
    }
}
