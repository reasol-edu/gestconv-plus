<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla communication y el enlace de notificación en partes y sanciones (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE communication (
                id                  CHAR(36)    NOT NULL,
                incident_report_id  CHAR(36)    DEFAULT NULL,
                sanction_id         CHAR(36)    DEFAULT NULL,
                method_id           CHAR(36)    NOT NULL,
                performed_by_id     CHAR(36)    NOT NULL,
                performed_at        DATETIME    NOT NULL,
                description         CLOB        DEFAULT NULL,
                result              VARCHAR(20) NOT NULL,
                created_at          DATETIME    NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_communication_report      FOREIGN KEY (incident_report_id) REFERENCES incident_report (id) ON DELETE CASCADE,
                CONSTRAINT fk_communication_sanction     FOREIGN KEY (sanction_id)        REFERENCES sanction (id)        ON DELETE CASCADE,
                CONSTRAINT fk_communication_method       FOREIGN KEY (method_id)          REFERENCES communication_method (id),
                CONSTRAINT fk_communication_performed_by FOREIGN KEY (performed_by_id)    REFERENCES teacher (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_communication_report      ON communication (incident_report_id)');
        $this->addSql('CREATE INDEX idx_communication_sanction     ON communication (sanction_id)');
        $this->addSql('CREATE INDEX idx_communication_method       ON communication (method_id)');
        $this->addSql('CREATE INDEX idx_communication_performed_by ON communication (performed_by_id)');

        $this->addSql('ALTER TABLE incident_report ADD COLUMN notified_communication_id CHAR(36) DEFAULT NULL REFERENCES communication (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_ir_notified_communication ON incident_report (notified_communication_id)');

        $this->addSql('ALTER TABLE sanction ADD COLUMN notified_communication_id CHAR(36) DEFAULT NULL REFERENCES communication (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_sanction_notified_communication ON sanction (notified_communication_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP INDEX idx_sanction_notified_communication');
        $this->addSql('DROP INDEX idx_ir_notified_communication');
        $this->addSql('DROP TABLE communication');
    }
}
