<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla communication y el enlace de notificación en partes y sanciones (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE communication (
                id                  BINARY(16)  NOT NULL,
                incident_report_id  BINARY(16)  DEFAULT NULL,
                sanction_id         BINARY(16)  DEFAULT NULL,
                method_id           BINARY(16)  NOT NULL,
                performed_by_id     BINARY(16)  NOT NULL,
                performed_at        DATETIME    NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                description         LONGTEXT    DEFAULT NULL,
                result              VARCHAR(20) NOT NULL,
                created_at          DATETIME    NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_communication_report      (incident_report_id),
                INDEX idx_communication_sanction     (sanction_id),
                INDEX idx_communication_method       (method_id),
                INDEX idx_communication_performed_by (performed_by_id),
                CONSTRAINT fk_communication_report      FOREIGN KEY (incident_report_id) REFERENCES incident_report (id) ON DELETE CASCADE,
                CONSTRAINT fk_communication_sanction     FOREIGN KEY (sanction_id)        REFERENCES sanction (id)        ON DELETE CASCADE,
                CONSTRAINT fk_communication_method       FOREIGN KEY (method_id)          REFERENCES communication_method (id),
                CONSTRAINT fk_communication_performed_by FOREIGN KEY (performed_by_id)    REFERENCES teacher (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);

        $this->addSql('ALTER TABLE incident_report ADD COLUMN notified_communication_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE incident_report ADD CONSTRAINT fk_ir_notified_communication FOREIGN KEY (notified_communication_id) REFERENCES communication (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_ir_notified_communication ON incident_report (notified_communication_id)');

        $this->addSql('ALTER TABLE sanction ADD COLUMN notified_communication_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE sanction ADD CONSTRAINT fk_sanction_notified_communication FOREIGN KEY (notified_communication_id) REFERENCES communication (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_sanction_notified_communication ON sanction (notified_communication_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql('ALTER TABLE sanction DROP FOREIGN KEY fk_sanction_notified_communication');
        $this->addSql('ALTER TABLE sanction DROP INDEX idx_sanction_notified_communication');
        $this->addSql('ALTER TABLE sanction DROP COLUMN notified_communication_id');

        $this->addSql('ALTER TABLE incident_report DROP FOREIGN KEY fk_ir_notified_communication');
        $this->addSql('ALTER TABLE incident_report DROP INDEX idx_ir_notified_communication');
        $this->addSql('ALTER TABLE incident_report DROP COLUMN notified_communication_id');

        $this->addSql('DROP TABLE communication');
    }
}
