<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000065 extends AbstractMigration
{
    /** Eventos de aviso del módulo (un ajuste «recibir este aviso» por cada uno). */
    private const array EVENTS = ['issue_created', 'issue_status', 'issue_priority', 'issue_observation', 'issue_changed', 'issue_overdue'];

    /** Prioridades por defecto, de más a menos importante: nombre, color, plazo máximo (días lectivos). */
    private const array PRIORITIES = [
        ['Crítica', '#dc2626', 1],
        ['Alta', '#ea580c', 7],
        ['Media', '#ca8a04', 0],
        ['Baja', '#16a34a', 0],
    ];

    public function getDescription(): string
    {
        return 'Módulo de incidencias: tipos jerárquicos con responsables, prioridades, incidencias con observaciones, adjuntos e historial, el ajuste features.issues y los avisos notifications.receive_issue_* (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->addSql(<<<SQL
            CREATE TABLE issue_type (
                id                    BINARY(16)   NOT NULL,
                educational_centre_id BINARY(16)   NOT NULL,
                parent_id             BINARY(16)   DEFAULT NULL,
                name                  VARCHAR(255) NOT NULL,
                active                TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_issue_type_centre (educational_centre_id),
                INDEX idx_issue_type_parent (parent_id),
                CONSTRAINT fk_issue_type_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_type_parent FOREIGN KEY (parent_id) REFERENCES issue_type (id) ON DELETE RESTRICT
            ) $engine
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE issue_type_manager (
                issue_type_id BINARY(16) NOT NULL,
                teacher_id    BINARY(16) NOT NULL,
                PRIMARY KEY (issue_type_id, teacher_id),
                INDEX idx_itm_type (issue_type_id),
                INDEX idx_itm_teacher (teacher_id),
                CONSTRAINT fk_itm_type FOREIGN KEY (issue_type_id) REFERENCES issue_type (id) ON DELETE CASCADE,
                CONSTRAINT fk_itm_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE
            ) $engine
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE issue_priority (
                id                    BINARY(16)   NOT NULL,
                educational_centre_id BINARY(16)   NOT NULL,
                name                  VARCHAR(100) NOT NULL,
                color                 VARCHAR(7)   NOT NULL,
                position              INT          NOT NULL,
                recommended_days      INT          NOT NULL DEFAULT 0,
                max_days              INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                INDEX idx_issue_priority_centre (educational_centre_id),
                CONSTRAINT fk_issue_priority_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            ) $engine
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE issue (
                id                    BINARY(16)  NOT NULL,
                educational_centre_id BINARY(16)  NOT NULL,
                academic_year_id      BINARY(16)  NOT NULL,
                number                INT         NOT NULL,
                type_id               BINARY(16)  NOT NULL,
                location_id           BINARY(16)  DEFAULT NULL,
                booking_id            BINARY(16)  DEFAULT NULL,
                reporter_id           BINARY(16)  NOT NULL,
                description           LONGTEXT    NOT NULL,
                status                VARCHAR(20) NOT NULL,
                priority_id           BINARY(16)  DEFAULT NULL,
                duplicate_of_id       BINARY(16)  DEFAULT NULL,
                created_at            DATETIME    NOT NULL,
                updated_at            DATETIME    NOT NULL,
                closed_at             DATETIME    DEFAULT NULL,
                recommended_due       DATE        DEFAULT NULL,
                max_due               DATE        DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uq_issue_centre_number (educational_centre_id, number),
                INDEX idx_issue_status (status),
                INDEX idx_issue_created (created_at),
                INDEX idx_issue_year (academic_year_id),
                INDEX idx_issue_type_ref (type_id),
                INDEX idx_issue_location (location_id),
                INDEX idx_issue_reporter (reporter_id),
                INDEX idx_issue_booking (booking_id),
                INDEX idx_issue_priority_ref (priority_id),
                INDEX idx_issue_duplicate (duplicate_of_id),
                CONSTRAINT fk_issue_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_issue_type FOREIGN KEY (type_id) REFERENCES issue_type (id),
                CONSTRAINT fk_issue_location FOREIGN KEY (location_id) REFERENCES location_option (id) ON DELETE SET NULL,
                CONSTRAINT fk_issue_booking FOREIGN KEY (booking_id) REFERENCES resource_booking (id) ON DELETE SET NULL,
                CONSTRAINT fk_issue_reporter FOREIGN KEY (reporter_id) REFERENCES teacher (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_priority FOREIGN KEY (priority_id) REFERENCES issue_priority (id) ON DELETE RESTRICT,
                CONSTRAINT fk_issue_duplicate FOREIGN KEY (duplicate_of_id) REFERENCES issue (id) ON DELETE SET NULL
            ) $engine
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE issue_observation (
                id         BINARY(16) NOT NULL,
                issue_id   BINARY(16) NOT NULL,
                author_id  BINARY(16) NOT NULL,
                content    LONGTEXT   NOT NULL,
                created_at DATETIME   NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_issue_obs_issue (issue_id),
                INDEX idx_issue_obs_author (author_id),
                CONSTRAINT fk_issue_obs_issue FOREIGN KEY (issue_id) REFERENCES issue (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_obs_author FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE CASCADE
            ) $engine
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE issue_attachment (
                id             BINARY(16)   NOT NULL,
                issue_id       BINARY(16)   NOT NULL,
                uploaded_by_id BINARY(16)   DEFAULT NULL,
                filename       VARCHAR(255) NOT NULL,
                mime_type      VARCHAR(100) NOT NULL,
                size           INT          NOT NULL,
                content        LONGBLOB     NOT NULL,
                thumbnail      LONGBLOB     DEFAULT NULL,
                created_at     DATETIME     NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_issue_att_issue (issue_id),
                INDEX idx_issue_att_user (uploaded_by_id),
                CONSTRAINT fk_issue_att_issue FOREIGN KEY (issue_id) REFERENCES issue (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_att_user FOREIGN KEY (uploaded_by_id) REFERENCES teacher (id) ON DELETE SET NULL
            ) $engine
        SQL);

        $this->addSql(<<<SQL
            CREATE TABLE issue_change (
                id         BINARY(16)  NOT NULL,
                issue_id   BINARY(16)  NOT NULL,
                author_id  BINARY(16)  DEFAULT NULL,
                field      VARCHAR(40) NOT NULL,
                old_value  LONGTEXT    DEFAULT NULL,
                new_value  LONGTEXT    DEFAULT NULL,
                created_at DATETIME    NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_issue_change_issue (issue_id),
                INDEX idx_issue_change_author (author_id),
                CONSTRAINT fk_issue_change_issue FOREIGN KEY (issue_id) REFERENCES issue (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_change_author FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE SET NULL
            ) $engine
        SQL);

        $rows = ["(UNHEX(REPLACE(UUID(), '-', '')), 'features.issues', 'boolean', 'true', 1, 1, 0, NULL, NULL, 'settings.category.features', 6, 70)"];
        foreach (self::EVENTS as $index => $event) {
            $rows[] = sprintf("(UNHEX(REPLACE(UUID(), '-', '')), 'notifications.receive_%s', 'boolean', 'true', 1, 1, 1, NULL, NULL, 'settings.category.email_receive_issues', 56, %d)", $event, ($index + 1) * 10);
        }
        $this->addSql(
            'INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES '
            . implode(', ', $rows)
        );

        // Prioridades por defecto en los centros que ya existen (los nuevos las reciben de IssuePrioritySeeder).
        foreach (self::PRIORITIES as $position => [$name, $color, $max]) {
            $this->addSql(sprintf(
                "INSERT INTO issue_priority (id, educational_centre_id, name, color, position, recommended_days, max_days) SELECT UNHEX(REPLACE(UUID(), '-', '')), id, '%s', '%s', %d, 0, %d FROM educational_centre",
                $name,
                $color,
                $position,
                $max,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $keys = ['features.issues', ...array_map(static fn (string $event): string => 'notifications.receive_' . $event, self::EVENTS)];
        foreach ($keys as $key) {
            foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
                $this->addSql("DELETE FROM $table WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` = '$key')");
            }
            $this->addSql("DELETE FROM setting_definition WHERE `key` = '$key'");
        }

        foreach (['issue_change', 'issue_attachment', 'issue_observation', 'issue', 'issue_priority', 'issue_type_manager', 'issue_type'] as $table) {
            $this->addSql("DROP TABLE $table");
        }
    }
}
