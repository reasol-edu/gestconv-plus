<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000028 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade campos de seguimiento a sanction y la tabla sanction_observation (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE sanction
                ADD COLUMN measures_effective    TINYINT(1)   NULL AFTER no_measure_reason,
                ADD COLUMN family_claimed        TINYINT(1)   NULL AFTER measures_effective,
                ADD COLUMN family_claim_attitude LONGTEXT     NULL AFTER family_claimed,
                ADD COLUMN registered_in_seneca  TINYINT(1)   NULL AFTER family_claim_attitude,
                ADD COLUMN calendar_label        VARCHAR(255) NULL AFTER registered_in_seneca
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_observation (
                id               BINARY(16) NOT NULL,
                sanction_id      BINARY(16) NOT NULL,
                registered_by_id BINARY(16) NOT NULL,
                registered_at    DATETIME   NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                text             LONGTEXT   NOT NULL,
                created_at       DATETIME   NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_so_sanction      (sanction_id),
                INDEX idx_so_registered_by (registered_by_id),
                CONSTRAINT fk_so_sanction      FOREIGN KEY (sanction_id)      REFERENCES sanction (id) ON DELETE CASCADE,
                CONSTRAINT fk_so_registered_by FOREIGN KEY (registered_by_id) REFERENCES teacher (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('DROP TABLE sanction_observation');
        $this->addSql(<<<'SQL'
            ALTER TABLE sanction
                DROP COLUMN measures_effective,
                DROP COLUMN family_claimed,
                DROP COLUMN family_claim_attitude,
                DROP COLUMN registered_in_seneca,
                DROP COLUMN calendar_label
        SQL);
    }
}
