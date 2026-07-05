<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla email_notification_log (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE email_notification_log (
                id                     UUID         NOT NULL,
                educational_centre_id  UUID         NOT NULL,
                recipient_id           UUID         DEFAULT NULL,
                recipient_name         VARCHAR(200) NOT NULL,
                event_key              VARCHAR(50)  NOT NULL,
                subject                VARCHAR(255) NOT NULL,
                success                BOOLEAN      NOT NULL,
                error_message          TEXT         DEFAULT NULL,
                sent_at                TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql("COMMENT ON COLUMN email_notification_log.sent_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX idx_enl_centre_sent ON email_notification_log (educational_centre_id, sent_at)');
        $this->addSql('CREATE INDEX idx_enl_recipient    ON email_notification_log (recipient_id)');
        $this->addSql('CREATE INDEX idx_enl_event        ON email_notification_log (event_key)');
        $this->addSql('ALTER TABLE email_notification_log ADD CONSTRAINT fk_enl_centre    FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE  NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE email_notification_log ADD CONSTRAINT fk_enl_recipient  FOREIGN KEY (recipient_id)          REFERENCES teacher (id)             ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('DROP TABLE email_notification_log');
    }
}
