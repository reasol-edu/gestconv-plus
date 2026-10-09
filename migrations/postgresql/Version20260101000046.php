<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000046 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resumen diario de avisos por correo: tabla email_digest_item y ajustes de forma y hora de envío (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE email_digest_item (
                id                     UUID         NOT NULL,
                educational_centre_id  UUID         NOT NULL,
                recipient_id           UUID         NOT NULL,
                event_key              VARCHAR(50)  NOT NULL,
                params                 JSON         NOT NULL,
                url                    VARCHAR(500) DEFAULT NULL,
                created_at             TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                sent_at                TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_edi_recipient_sent ON email_digest_item (recipient_id, sent_at)');
        $this->addSql('CREATE INDEX idx_edi_centre ON email_digest_item (educational_centre_id)');
        $this->addSql('ALTER TABLE email_digest_item ADD CONSTRAINT fk_edi_centre    FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE email_digest_item ADD CONSTRAINT fk_edi_recipient FOREIGN KEY (recipient_id)          REFERENCES teacher (id)             ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (gen_random_uuid(), 'notifications.email_delivery',    'choice',  'daily_digest', TRUE, TRUE, TRUE, NULL, NULL, 'immediate,daily_digest', 'settings.category.email_alerts', 50, 1),
                (gen_random_uuid(), 'notifications.email_digest_hour', 'integer', '7',         TRUE, TRUE, TRUE, 0,    23,   NULL,                     'settings.category.email_alerts', 50, 2)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.email_delivery', 'notifications.email_digest_hour')");
        $this->addSql('DROP TABLE email_digest_item');
    }
}
