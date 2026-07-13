<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000028 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade campos de seguimiento a sanction y la tabla sanction_observation (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE sanction ADD COLUMN measures_effective    BOOLEAN      NULL');
        $this->addSql('ALTER TABLE sanction ADD COLUMN family_claimed        BOOLEAN      NULL');
        $this->addSql('ALTER TABLE sanction ADD COLUMN family_claim_attitude CLOB         NULL');
        $this->addSql('ALTER TABLE sanction ADD COLUMN registered_in_seneca  BOOLEAN      NULL');
        $this->addSql('ALTER TABLE sanction ADD COLUMN calendar_label        VARCHAR(255) NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_observation (
                id               CHAR(36) NOT NULL,
                sanction_id      CHAR(36) NOT NULL,
                registered_by_id CHAR(36) NOT NULL,
                registered_at    DATETIME NOT NULL,
                text             CLOB     NOT NULL,
                created_at       DATETIME NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_so_sanction      FOREIGN KEY (sanction_id)      REFERENCES sanction (id) ON DELETE CASCADE,
                CONSTRAINT fk_so_registered_by FOREIGN KEY (registered_by_id) REFERENCES teacher (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_so_sanction      ON sanction_observation (sanction_id)');
        $this->addSql('CREATE INDEX idx_so_registered_by ON sanction_observation (registered_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE sanction_observation');
        $this->addSql('ALTER TABLE sanction DROP COLUMN measures_effective');
        $this->addSql('ALTER TABLE sanction DROP COLUMN family_claimed');
        $this->addSql('ALTER TABLE sanction DROP COLUMN family_claim_attitude');
        $this->addSql('ALTER TABLE sanction DROP COLUMN registered_in_seneca');
        $this->addSql('ALTER TABLE sanction DROP COLUMN calendar_label');
    }
}
