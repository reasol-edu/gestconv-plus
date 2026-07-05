<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la tabla email_notification_log (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE email_notification_log (
                id                     BINARY(16)   NOT NULL,
                educational_centre_id  BINARY(16)   NOT NULL,
                recipient_id           BINARY(16)   DEFAULT NULL,
                recipient_name         VARCHAR(200) NOT NULL,
                event_key              VARCHAR(50)  NOT NULL,
                subject                VARCHAR(255) NOT NULL,
                success                TINYINT(1)   NOT NULL,
                error_message          LONGTEXT     DEFAULT NULL,
                sent_at                DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_enl_centre_sent (educational_centre_id, sent_at),
                INDEX idx_enl_recipient    (recipient_id),
                INDEX idx_enl_event        (event_key),
                CONSTRAINT fk_enl_centre    FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_enl_recipient FOREIGN KEY (recipient_id)          REFERENCES teacher (id)             ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL / MariaDB.'
        );

        $this->addSql('DROP TABLE email_notification_log');
    }
}
