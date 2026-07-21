<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade created_at a incident_report y el CHECK de exclusividad parte/sanción en communication (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql("ALTER TABLE incident_report ADD created_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('UPDATE incident_report SET created_at = occurred_at');
        $this->addSql("ALTER TABLE incident_report MODIFY created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");

        $this->addSql('ALTER TABLE communication ADD CONSTRAINT chk_comm_target_xor CHECK ((incident_report_id IS NULL) <> (sanction_id IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql('ALTER TABLE communication DROP CONSTRAINT chk_comm_target_xor');
        $this->addSql('ALTER TABLE incident_report DROP COLUMN created_at');
    }
}
