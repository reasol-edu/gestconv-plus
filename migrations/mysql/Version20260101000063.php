<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000063 extends AbstractMigration
{
    private const array KEYS = [
        'notifications.email_booking_issue_reported',
        'notifications.email_booking_issue_answered',
    ];

    public function getDescription(): string
    {
        return 'Incidencias de las reservas (columnas issue_* de resource_booking) y los dos avisos por correo asociados (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE resource_booking
                ADD issue_status VARCHAR(16) DEFAULT NULL,
                ADD issue_description LONGTEXT DEFAULT NULL,
                ADD issue_reported_at DATETIME DEFAULT NULL,
                ADD issue_comment LONGTEXT DEFAULT NULL,
                ADD issue_responded_by_id BINARY(16) DEFAULT NULL,
                ADD issue_responded_at DATETIME DEFAULT NULL,
                ADD issue_response_seen TINYINT(1) NOT NULL DEFAULT 1
        SQL);
        $this->addSql('CREATE INDEX idx_resource_booking_issue ON resource_booking (issue_status)');
        $this->addSql('CREATE INDEX idx_resource_booking_issue_by ON resource_booking (issue_responded_by_id)');
        $this->addSql('ALTER TABLE resource_booking ADD CONSTRAINT fk_resource_booking_issue_by FOREIGN KEY (issue_responded_by_id) REFERENCES teacher (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.email_booking_issue_reported', 'boolean', 'true', 1, 1, 1, NULL, NULL, 'settings.category.email_alerts', 50, 150),
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.email_booking_issue_answered', 'boolean', 'true', 1, 1, 1, NULL, NULL, 'settings.category.email_alerts', 50, 160)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        foreach (self::KEYS as $key) {
            foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
                $this->addSql("DELETE FROM $table WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` = '$key')");
            }
            $this->addSql("DELETE FROM setting_definition WHERE `key` = '$key'");
        }

        $this->addSql('ALTER TABLE resource_booking DROP FOREIGN KEY fk_resource_booking_issue_by');
        $this->addSql('DROP INDEX idx_resource_booking_issue_by ON resource_booking');
        $this->addSql('DROP INDEX idx_resource_booking_issue ON resource_booking');
        $this->addSql(<<<'SQL'
            ALTER TABLE resource_booking
                DROP issue_status,
                DROP issue_description,
                DROP issue_reported_at,
                DROP issue_comment,
                DROP issue_responded_by_id,
                DROP issue_responded_at,
                DROP issue_response_seen
        SQL);
    }
}
