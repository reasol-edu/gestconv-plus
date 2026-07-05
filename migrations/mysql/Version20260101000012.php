<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla incident_report_observation (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE incident_report_observation (
                id                  BINARY(16) NOT NULL,
                incident_report_id  BINARY(16) NOT NULL,
                registered_by_id    BINARY(16) NOT NULL,
                registered_at       DATETIME   NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                text                LONGTEXT   NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_iro_report        (incident_report_id),
                INDEX idx_iro_registered_by (registered_by_id),
                CONSTRAINT fk_iro_report        FOREIGN KEY (incident_report_id) REFERENCES incident_report (id) ON DELETE CASCADE,
                CONSTRAINT fk_iro_registered_by FOREIGN KEY (registered_by_id)   REFERENCES teacher (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql('DROP TABLE incident_report_observation');
    }
}
