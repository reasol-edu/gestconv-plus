<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000066 extends AbstractMigration
{
    /** Los avisos de las incidencias de reservas, que pasan a ser los del módulo de incidencias. */
    private const array OLD_EVENTS = ['booking_issue_reported', 'booking_issue_received', 'booking_issue_resolved'];

    private const string ROOT = 'Reservas de recursos';

    private const string NEW_ID = "UNHEX(REPLACE(UUID(), '-', ''))";

    public function getDescription(): string
    {
        return 'Las incidencias de las reservas pasan al módulo de incidencias (un tipo «Reservas de recursos › categoría» con los responsables de la categoría) y se retiran sus columnas y avisos (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $newId     = self::NEW_ID;
        $withIssue = <<<'SQL'
            FROM resource_booking b
            JOIN shared_resource r ON r.id = b.resource_id
            JOIN resource_category c ON c.id = r.category_id
            WHERE b.issue_status IS NOT NULL
        SQL;

        // Un tipo raíz por centro y, debajo, uno por categoría de recursos con incidencias (con sus responsables).
        $this->addSql(sprintf(
            "INSERT INTO issue_type (id, educational_centre_id, parent_id, name, active)
             SELECT %s, x.centre_id, NULL, '%s', 1
             FROM (SELECT DISTINCT c.educational_centre_id AS centre_id %s) x",
            $newId,
            self::ROOT,
            $withIssue,
        ));
        $this->addSql(sprintf(
            "INSERT INTO issue_type (id, educational_centre_id, parent_id, name, active)
             SELECT %s, x.centre_id, root.id, x.name, 1
             FROM (SELECT DISTINCT c.educational_centre_id AS centre_id, c.name AS name %s) x
             JOIN issue_type root ON root.educational_centre_id = x.centre_id AND root.parent_id IS NULL AND root.name = '%s'",
            $newId,
            $withIssue,
            self::ROOT,
        ));
        $this->addSql(sprintf(
            "INSERT INTO issue_type_manager (issue_type_id, teacher_id)
             SELECT DISTINCT t.id, m.teacher_id
             FROM issue_type t
             JOIN issue_type root ON root.id = t.parent_id AND root.name = '%s'
             JOIN resource_category c ON c.educational_centre_id = t.educational_centre_id AND c.name = t.name
             JOIN resource_category_manager m ON m.resource_category_id = c.id
             WHERE NOT EXISTS (SELECT 1 FROM issue_type_manager x WHERE x.issue_type_id = t.id AND x.teacher_id = m.teacher_id)",
            self::ROOT,
        ));

        // Las incidencias: «registrada» y «recibida» quedan abiertas (la recibida lleva su comentario como observación) y
        // «solucionada» pasa a solucionada. Sin prioridad ni plazos: se asignan al gestionarlas.
        $this->addSql(sprintf(
            "INSERT INTO issue (id, educational_centre_id, academic_year_id, number, type_id, location_id, booking_id, reporter_id, description, status, priority_id, duplicate_of_id, created_at, updated_at, closed_at, recommended_due, max_due)
             SELECT %s, s.centre_id, s.academic_year_id,
                    s.rn + COALESCE((SELECT MAX(i2.number) FROM issue i2 WHERE i2.educational_centre_id = s.centre_id), 0),
                    t.id, NULL, s.booking_id, s.teacher_id,
                    CONCAT('<p>', REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(s.issue_description, ''), '&', '&amp;'), '<', '&lt;'), '>', '&gt;'), CHAR(10), '<br>'), '</p>'),
                    s.new_status, NULL, NULL, s.reported_at, COALESCE(s.responded_at, s.reported_at),
                    CASE WHEN s.new_status = 'solved' THEN COALESCE(s.responded_at, s.reported_at) ELSE NULL END, NULL, NULL
             FROM (
                 SELECT b.id AS booking_id, b.academic_year_id, b.teacher_id, b.issue_description,
                        b.issue_reported_at AS reported_at, b.issue_responded_at AS responded_at,
                        c.educational_centre_id AS centre_id, c.name AS category_name,
                        CASE WHEN b.issue_status = 'resolved' THEN 'solved' ELSE 'open' END AS new_status,
                        ROW_NUMBER() OVER (PARTITION BY c.educational_centre_id ORDER BY b.issue_reported_at, b.id) AS rn
                 %s
             ) s
             JOIN issue_type root ON root.educational_centre_id = s.centre_id AND root.parent_id IS NULL AND root.name = '%s'
             JOIN issue_type t ON t.parent_id = root.id AND t.name = s.category_name",
            $newId,
            $withIssue,
            self::ROOT,
        ));

        $this->addSql(sprintf(
            "INSERT INTO issue_observation (id, issue_id, author_id, content, created_at)
             SELECT %s, i.id, b.issue_responded_by_id,
                    CASE WHEN b.issue_status = 'received' THEN CONCAT('Marcada como recibida. ', b.issue_comment) ELSE b.issue_comment END,
                    COALESCE(b.issue_responded_at, i.created_at)
             FROM issue i JOIN resource_booking b ON b.id = i.booking_id
             WHERE b.issue_status IN ('received', 'resolved') AND b.issue_comment IS NOT NULL AND b.issue_responded_by_id IS NOT NULL",
            $newId,
        ));

        // Historial: el alta y, en las solucionadas, el cierre.
        $this->addSql(sprintf(
            "INSERT INTO issue_change (id, issue_id, author_id, field, old_value, new_value, created_at)
             SELECT %s, i.id, i.reporter_id, 'created', NULL, CONCAT(root.name, ' › ', t.name), i.created_at
             FROM issue i
             JOIN issue_type t ON t.id = i.type_id
             JOIN issue_type root ON root.id = t.parent_id
             JOIN resource_booking b ON b.id = i.booking_id
             WHERE b.issue_status IS NOT NULL",
            $newId,
        ));
        $this->addSql(sprintf(
            "INSERT INTO issue_change (id, issue_id, author_id, field, old_value, new_value, created_at)
             SELECT %s, i.id, b.issue_responded_by_id, 'status', 'open', 'solved', COALESCE(b.issue_responded_at, i.created_at)
             FROM issue i JOIN resource_booking b ON b.id = i.booking_id
             WHERE b.issue_status = 'resolved'",
            $newId,
        ));

        // Se retira la función antigua: sus columnas y sus tres avisos (con los valores que cada nivel hubiera fijado).
        foreach (self::OLD_EVENTS as $event) {
            $key = 'notifications.receive_' . $event;
            foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
                $this->addSql("DELETE FROM $table WHERE definition_id IN (SELECT id FROM (SELECT id FROM setting_definition WHERE `key` = '$key') d)");
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

    public function down(Schema $schema): void
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

        // Las incidencias migradas se quedan en el módulo de incidencias; solo se vuelven a crear los avisos.
        foreach (self::OLD_EVENTS as $index => $event) {
            $this->addSql(sprintf(
                "INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES (%s, '%s', 'boolean', 'true', 1, 1, 1, NULL, NULL, 'settings.category.email_receive_bookings', 55, %d)",
                self::NEW_ID,
                'notifications.receive_' . $event,
                (16 + $index) * 10,
            ));
        }
    }
}
