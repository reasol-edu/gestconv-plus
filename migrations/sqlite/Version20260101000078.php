<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000078 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avisos push: suscripciones de dispositivos y marca de aviso de salida larga (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE push_subscription (
                id            CHAR(36)     NOT NULL,
                teacher_id    CHAR(36)     NOT NULL,
                endpoint_hash VARCHAR(64)  NOT NULL,
                endpoint      CLOB         NOT NULL,
                p256dh        VARCHAR(255) NOT NULL,
                auth_token    VARCHAR(255) NOT NULL,
                user_agent    VARCHAR(255) DEFAULT NULL,
                created_at    DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_push_subscription_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_push_subscription_endpoint ON push_subscription (endpoint_hash)');
        $this->addSql('CREATE INDEX idx_push_subscription_teacher ON push_subscription (teacher_id)');

        $this->addSql('ALTER TABLE student_exit ADD COLUMN long_notice_sent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE student_exit DROP COLUMN long_notice_sent_at');
        $this->addSql('DROP TABLE push_subscription');
    }
}
