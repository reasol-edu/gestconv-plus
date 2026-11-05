<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000063 extends AbstractMigration
{
    /**
     * Un ajuste «recibir este aviso» por cada tipo de aviso por correo (el mismo nombre que su evento), con el grupo
     * (categoría de ajustes) en que se muestra.
     */
    private const array EVENTS = [
        'report_created' => 'reports', 'report_notified' => 'reports', 'report_modified' => 'reports', 'report_deleted' => 'reports',
        'report_prescribed' => 'reports', 'report_auto_prescribed' => 'reports', 'report_sanctioned' => 'reports',
        'report_sanctionable_committee' => 'reports', 'report_prescription_warning' => 'reports',
        'sanction_notified' => 'sanctions', 'sanction_prescribed' => 'sanctions', 'sanction_auto_prescribed' => 'sanctions',
        'sanction_prescription_warning' => 'sanctions',
        'sanction_task_assigned' => 'tasks', 'sanction_task_reminder' => 'tasks', 'daily_note_threshold' => 'tasks',
        'booking_requested' => 'bookings', 'booking_approved' => 'bookings', 'booking_rejected' => 'bookings',
        'booking_issue_reported' => 'bookings', 'booking_issue_received' => 'bookings', 'booking_issue_resolved' => 'bookings',
    ];

    /** Orden de las categorías de ajustes de cada grupo. */
    private const array GROUP_ORDER = ['reports' => 52, 'sanctions' => 53, 'tasks' => 54, 'bookings' => 55];

    public function getDescription(): string
    {
        return 'Incidencias de las reservas (columnas issue_* de resource_booking) y ajustes notifications.receive_* (recibir cada tipo de aviso, a nivel global, de centro y de docente) (MySQL / MariaDB)';
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

        $rows = [];
        $position = 0;
        foreach (self::EVENTS as $event => $group) {
            $rows[] = sprintf("(UNHEX(REPLACE(UUID(), '-', '')), 'notifications.receive_%s', 'boolean', 'true', 1, 1, 1, NULL, NULL, 'settings.category.email_receive_%s', %d, %d)", $event, $group, self::GROUP_ORDER[$group], ++$position * 10);
        }
        $this->addSql(
            'INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES '
            . implode(', ', $rows)
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        foreach (array_keys(self::EVENTS) as $event) {
            $key = 'notifications.receive_' . $event;
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
