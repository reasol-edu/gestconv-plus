<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla communication y el enlace de notificación en partes y sanciones (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE communication (
                id                  UUID         NOT NULL,
                incident_report_id  UUID         DEFAULT NULL,
                sanction_id         UUID         DEFAULT NULL,
                method_id           UUID         NOT NULL,
                performed_by_id     UUID         NOT NULL,
                performed_at        TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                description         TEXT         DEFAULT NULL,
                result              VARCHAR(20)  NOT NULL,
                created_at          TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN communication.performed_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN communication.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_communication_report      ON communication (incident_report_id)');
        $this->addSql('CREATE INDEX idx_communication_sanction     ON communication (sanction_id)');
        $this->addSql('CREATE INDEX idx_communication_method       ON communication (method_id)');
        $this->addSql('CREATE INDEX idx_communication_performed_by ON communication (performed_by_id)');
        $this->addSql('ALTER TABLE communication ADD CONSTRAINT fk_communication_report      FOREIGN KEY (incident_report_id) REFERENCES incident_report (id)      ON DELETE CASCADE  NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication ADD CONSTRAINT fk_communication_sanction     FOREIGN KEY (sanction_id)        REFERENCES sanction (id)              ON DELETE CASCADE  NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication ADD CONSTRAINT fk_communication_method       FOREIGN KEY (method_id)          REFERENCES communication_method (id)                     NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication ADD CONSTRAINT fk_communication_performed_by FOREIGN KEY (performed_by_id)    REFERENCES teacher (id)                                  NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE incident_report ADD COLUMN notified_communication_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE incident_report ADD CONSTRAINT fk_ir_notified_communication FOREIGN KEY (notified_communication_id) REFERENCES communication (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_ir_notified_communication ON incident_report (notified_communication_id)');

        $this->addSql('ALTER TABLE sanction ADD COLUMN notified_communication_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE sanction ADD CONSTRAINT fk_sanction_notified_communication FOREIGN KEY (notified_communication_id) REFERENCES communication (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_sanction_notified_communication ON sanction (notified_communication_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE sanction DROP CONSTRAINT fk_sanction_notified_communication');
        $this->addSql('DROP INDEX idx_sanction_notified_communication');
        $this->addSql('ALTER TABLE sanction DROP COLUMN notified_communication_id');

        $this->addSql('ALTER TABLE incident_report DROP CONSTRAINT fk_ir_notified_communication');
        $this->addSql('DROP INDEX idx_ir_notified_communication');
        $this->addSql('ALTER TABLE incident_report DROP COLUMN notified_communication_id');

        $this->addSql('DROP TABLE communication');
    }
}
