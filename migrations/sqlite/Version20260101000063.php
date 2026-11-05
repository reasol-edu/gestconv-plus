<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

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
        return 'Incidencias de las reservas (columnas issue_* de resource_booking) y ajustes notifications.receive_* (recibir cada tipo de aviso, a nivel global, de centro y de docente); repara los ids de los ajustes de la 062 (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE resource_booking ADD COLUMN issue_status VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE resource_booking ADD COLUMN issue_description CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE resource_booking ADD COLUMN issue_reported_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE resource_booking ADD COLUMN issue_comment CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE resource_booking ADD COLUMN issue_responded_by_id CHAR(36) DEFAULT NULL REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE resource_booking ADD COLUMN issue_responded_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE resource_booking ADD COLUMN issue_response_seen INTEGER NOT NULL DEFAULT 1');
        $this->addSql('CREATE INDEX idx_resource_booking_issue ON resource_booking (issue_status)');

        $position = 0;
        foreach (self::EVENTS as $event => $group) {
            $this->addSql(
                "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (?, ?, 'boolean', 'true', 1, 1, 1, NULL, NULL, NULL, ?, ?, ?)",
                [Uuid::v4()->toBinary(), 'notifications.receive_' . $event, 'settings.category.email_receive_' . $group, self::GROUP_ORDER[$group], ++$position * 10]
            );
        }

        $this->repairSettingIds();
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        foreach (array_keys(self::EVENTS) as $event) {
            $key = 'notifications.receive_' . $event;
            foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
                $this->addSql("DELETE FROM $table WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
            }
            $this->addSql("DELETE FROM setting_definition WHERE key = '$key'");
        }

        $this->addSql('DROP INDEX idx_resource_booking_issue');
        foreach (['issue_response_seen', 'issue_responded_at', 'issue_responded_by_id', 'issue_comment', 'issue_reported_at', 'issue_description', 'issue_status'] as $column) {
            $this->addSql("ALTER TABLE resource_booking DROP COLUMN $column");
        }
    }

    /**
     * La 062 sembró sus cinco ajustes con el UUID escrito como texto de 36 caracteres, un formato que el ORM no
     * puede localizar en SQLite (ver Version20260101000049): el ajuste se listaba, pero no se podía guardar. Cada id
     * erróneo pasa al formato de Doctrine, junto con las referencias de las tablas de valores.
     */
    private function repairSettingIds(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id FROM setting_definition WHERE typeof(id) = 'text' AND length(id) = 36"
        );
        if ($rows === []) {
            return;
        }

        $this->addSql('PRAGMA defer_foreign_keys = ON');
        foreach ($rows as $row) {
            $old = (string) $row['id'];
            if (!Uuid::isValid($old)) {
                continue;
            }
            $binary = Uuid::fromString($old)->toBinary();
            $this->addSql('UPDATE setting_definition SET id = ? WHERE id = ?', [$binary, $old]);
            foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
                $this->addSql("UPDATE $table SET definition_id = ? WHERE definition_id = ?", [$binary, $old]);
            }
        }
    }
}
