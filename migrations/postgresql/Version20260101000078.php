<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000078 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avisos push: suscripciones de dispositivos y marca de aviso de salida larga (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE push_subscription (
                id            UUID         NOT NULL,
                teacher_id    UUID         NOT NULL,
                endpoint_hash VARCHAR(64)  NOT NULL,
                endpoint      TEXT         NOT NULL,
                p256dh        VARCHAR(255) NOT NULL,
                auth_token    VARCHAR(255) NOT NULL,
                user_agent    VARCHAR(255) DEFAULT NULL,
                created_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_push_subscription_endpoint ON push_subscription (endpoint_hash)');
        $this->addSql('CREATE INDEX idx_push_subscription_teacher ON push_subscription (teacher_id)');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT fk_push_subscription_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE student_exit ADD long_notice_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE student_exit DROP long_notice_sent_at');
        $this->addSql('DROP TABLE push_subscription');
    }
}
