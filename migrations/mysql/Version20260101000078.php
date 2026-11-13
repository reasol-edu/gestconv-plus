<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000078 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avisos push: suscripciones de dispositivos y marca de aviso de salida larga (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE push_subscription (
                id            BINARY(16)   NOT NULL,
                teacher_id    BINARY(16)   NOT NULL,
                endpoint_hash VARCHAR(64)  NOT NULL,
                endpoint      LONGTEXT     NOT NULL,
                p256dh        VARCHAR(255) NOT NULL,
                auth_token    VARCHAR(255) NOT NULL,
                user_agent    VARCHAR(255) DEFAULT NULL,
                created_at    DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uq_push_subscription_endpoint (endpoint_hash),
                INDEX idx_push_subscription_teacher (teacher_id),
                CONSTRAINT fk_push_subscription_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql('ALTER TABLE student_exit ADD long_notice_sent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql('ALTER TABLE student_exit DROP long_notice_sent_at');
        $this->addSql('DROP TABLE push_subscription');
    }
}
