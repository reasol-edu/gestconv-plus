<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla email_notification_log (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE email_notification_log (
                id                     CHAR(36)     NOT NULL,
                educational_centre_id  CHAR(36)     NOT NULL,
                recipient_id           CHAR(36)     DEFAULT NULL,
                recipient_name         VARCHAR(200) NOT NULL,
                event_key              VARCHAR(50)  NOT NULL,
                subject                VARCHAR(255) NOT NULL,
                success                BOOLEAN      NOT NULL,
                error_message          CLOB         DEFAULT NULL,
                sent_at                DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_enl_centre    FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_enl_recipient FOREIGN KEY (recipient_id)          REFERENCES teacher (id)             ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_enl_centre_sent ON email_notification_log (educational_centre_id, sent_at)');
        $this->addSql('CREATE INDEX idx_enl_recipient    ON email_notification_log (recipient_id)');
        $this->addSql('CREATE INDEX idx_enl_event        ON email_notification_log (event_key)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE email_notification_log');
    }
}
