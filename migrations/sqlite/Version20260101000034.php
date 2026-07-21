<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade created_at a incident_report y el CHECK de exclusividad parte/sanción en communication (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("ALTER TABLE incident_report ADD COLUMN created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'");
        $this->addSql('UPDATE incident_report SET created_at = occurred_at');

        // SQLite no admite ADD CONSTRAINT: hay que reconstruir la tabla para añadir el CHECK
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql(<<<'SQL'
            CREATE TABLE __communication_new (
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
                CONSTRAINT fk_communication_report       FOREIGN KEY (incident_report_id) REFERENCES incident_report (id) ON DELETE CASCADE,
                CONSTRAINT fk_communication_sanction     FOREIGN KEY (sanction_id)        REFERENCES sanction (id)        ON DELETE CASCADE,
                CONSTRAINT fk_communication_method       FOREIGN KEY (method_id)          REFERENCES communication_method (id),
                CONSTRAINT fk_communication_performed_by FOREIGN KEY (performed_by_id)    REFERENCES teacher (id),
                CONSTRAINT chk_comm_target_xor CHECK ((incident_report_id IS NULL) <> (sanction_id IS NULL))
            )
        SQL);
        $this->addSql('INSERT INTO __communication_new (id, incident_report_id, sanction_id, method_id, performed_by_id, performed_at, description, result, created_at) SELECT id, incident_report_id, sanction_id, method_id, performed_by_id, performed_at, description, result, created_at FROM communication');
        $this->addSql('DROP TABLE communication');
        $this->addSql('ALTER TABLE __communication_new RENAME TO communication');
        $this->addSql('CREATE INDEX idx_communication_report       ON communication (incident_report_id)');
        $this->addSql('CREATE INDEX idx_communication_sanction     ON communication (sanction_id)');
        $this->addSql('CREATE INDEX idx_communication_method       ON communication (method_id)');
        $this->addSql('CREATE INDEX idx_communication_performed_by ON communication (performed_by_id)');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql(<<<'SQL'
            CREATE TABLE __communication_old (
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
                CONSTRAINT fk_communication_report       FOREIGN KEY (incident_report_id) REFERENCES incident_report (id) ON DELETE CASCADE,
                CONSTRAINT fk_communication_sanction     FOREIGN KEY (sanction_id)        REFERENCES sanction (id)        ON DELETE CASCADE,
                CONSTRAINT fk_communication_method       FOREIGN KEY (method_id)          REFERENCES communication_method (id),
                CONSTRAINT fk_communication_performed_by FOREIGN KEY (performed_by_id)    REFERENCES teacher (id)
            )
        SQL);
        $this->addSql('INSERT INTO __communication_old (id, incident_report_id, sanction_id, method_id, performed_by_id, performed_at, description, result, created_at) SELECT id, incident_report_id, sanction_id, method_id, performed_by_id, performed_at, description, result, created_at FROM communication');
        $this->addSql('DROP TABLE communication');
        $this->addSql('ALTER TABLE __communication_old RENAME TO communication');
        $this->addSql('CREATE INDEX idx_communication_report       ON communication (incident_report_id)');
        $this->addSql('CREATE INDEX idx_communication_sanction     ON communication (sanction_id)');
        $this->addSql('CREATE INDEX idx_communication_method       ON communication (method_id)');
        $this->addSql('CREATE INDEX idx_communication_performed_by ON communication (performed_by_id)');
        $this->addSql('PRAGMA foreign_keys = ON');

        $this->addSql('ALTER TABLE incident_report DROP COLUMN created_at');
    }
}
