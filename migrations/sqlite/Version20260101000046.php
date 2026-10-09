<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000046 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resumen diario de avisos por correo: tabla email_digest_item y ajustes de forma y hora de envío (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE email_digest_item (
                id                     CHAR(36)     NOT NULL,
                educational_centre_id  CHAR(36)     NOT NULL,
                recipient_id           CHAR(36)     NOT NULL,
                event_key              VARCHAR(50)  NOT NULL,
                params                 CLOB         NOT NULL,
                url                    VARCHAR(500) DEFAULT NULL,
                created_at             DATETIME     NOT NULL,
                sent_at                DATETIME     DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_edi_centre    FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_edi_recipient FOREIGN KEY (recipient_id)          REFERENCES teacher (id)             ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_edi_recipient_sent ON email_digest_item (recipient_id, sent_at)');
        $this->addSql('CREATE INDEX idx_edi_centre ON email_digest_item (educational_centre_id)');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-000000000044', 'notifications.email_delivery',    'choice',  'daily_digest', 1, 1, 1, NULL, NULL, 'immediate,daily_digest', 'settings.category.email_alerts', 50, 1),
            ('00000000-0000-4000-8000-000000000045', 'notifications.email_digest_hour', 'integer', '7',         1, 1, 1, 0,    23,   NULL,                     'settings.category.email_alerts', 50, 2)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.email_delivery', 'notifications.email_digest_hour')");
        $this->addSql('DROP TABLE email_digest_item');
    }
}
