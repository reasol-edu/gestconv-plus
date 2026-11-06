<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

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
        return 'Módulo de incidencias: tipos jerárquicos con responsables, prioridades, incidencias con observaciones, adjuntos e historial, el ajuste features.issues y los avisos notifications.receive_issue_* (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_type (
                id                    CHAR(36)     NOT NULL,
                educational_centre_id CHAR(36)     NOT NULL,
                parent_id             CHAR(36)     DEFAULT NULL,
                name                  VARCHAR(255) NOT NULL,
                active                INTEGER      NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                CONSTRAINT fk_issue_type_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_type_parent FOREIGN KEY (parent_id) REFERENCES issue_type (id) ON DELETE RESTRICT
            )
        SQL);
        $this->addSql('CREATE INDEX idx_issue_type_centre ON issue_type (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_issue_type_parent ON issue_type (parent_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_type_manager (
                issue_type_id CHAR(36) NOT NULL,
                teacher_id    CHAR(36) NOT NULL,
                PRIMARY KEY (issue_type_id, teacher_id),
                CONSTRAINT fk_itm_type FOREIGN KEY (issue_type_id) REFERENCES issue_type (id) ON DELETE CASCADE,
                CONSTRAINT fk_itm_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_itm_type ON issue_type_manager (issue_type_id)');
        $this->addSql('CREATE INDEX idx_itm_teacher ON issue_type_manager (teacher_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_priority (
                id                    CHAR(36)     NOT NULL,
                educational_centre_id CHAR(36)     NOT NULL,
                name                  VARCHAR(100) NOT NULL,
                color                 VARCHAR(7)   NOT NULL,
                position              INTEGER      NOT NULL,
                recommended_days      INTEGER      NOT NULL DEFAULT 0,
                max_days              INTEGER      NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                CONSTRAINT fk_issue_priority_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_issue_priority_centre ON issue_priority (educational_centre_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue (
                id                    CHAR(36)     NOT NULL,
                educational_centre_id CHAR(36)     NOT NULL,
                academic_year_id      CHAR(36)     NOT NULL,
                number                INTEGER      NOT NULL,
                type_id               CHAR(36)     NOT NULL,
                location_id           CHAR(36)     DEFAULT NULL,
                booking_id            CHAR(36)     DEFAULT NULL,
                reporter_id           CHAR(36)     NOT NULL,
                description           CLOB         NOT NULL,
                status                VARCHAR(20)  NOT NULL,
                priority_id           CHAR(36)     DEFAULT NULL,
                duplicate_of_id       CHAR(36)     DEFAULT NULL,
                created_at            DATETIME     NOT NULL,
                updated_at            DATETIME     NOT NULL,
                closed_at             DATETIME     DEFAULT NULL,
                recommended_due       DATE         DEFAULT NULL,
                max_due               DATE         DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_issue_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_issue_type FOREIGN KEY (type_id) REFERENCES issue_type (id),
                CONSTRAINT fk_issue_location FOREIGN KEY (location_id) REFERENCES location_option (id) ON DELETE SET NULL,
                CONSTRAINT fk_issue_booking FOREIGN KEY (booking_id) REFERENCES resource_booking (id) ON DELETE SET NULL,
                CONSTRAINT fk_issue_reporter FOREIGN KEY (reporter_id) REFERENCES teacher (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_priority FOREIGN KEY (priority_id) REFERENCES issue_priority (id) ON DELETE RESTRICT,
                CONSTRAINT fk_issue_duplicate FOREIGN KEY (duplicate_of_id) REFERENCES issue (id) ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_issue_centre_number ON issue (educational_centre_id, number)');
        $this->addSql('CREATE INDEX idx_issue_status ON issue (status)');
        $this->addSql('CREATE INDEX idx_issue_created ON issue (created_at)');
        $this->addSql('CREATE INDEX idx_issue_year ON issue (academic_year_id)');
        $this->addSql('CREATE INDEX idx_issue_type_ref ON issue (type_id)');
        $this->addSql('CREATE INDEX idx_issue_reporter ON issue (reporter_id)');
        $this->addSql('CREATE INDEX idx_issue_booking ON issue (booking_id)');
        $this->addSql('CREATE INDEX idx_issue_priority_ref ON issue (priority_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_observation (
                id         CHAR(36)  NOT NULL,
                issue_id   CHAR(36)  NOT NULL,
                author_id  CHAR(36)  NOT NULL,
                content    CLOB      NOT NULL,
                created_at DATETIME  NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_issue_obs_issue FOREIGN KEY (issue_id) REFERENCES issue (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_obs_author FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_issue_obs_issue ON issue_observation (issue_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_attachment (
                id           CHAR(36)     NOT NULL,
                issue_id     CHAR(36)     NOT NULL,
                uploaded_by_id CHAR(36)   DEFAULT NULL,
                filename     VARCHAR(255) NOT NULL,
                mime_type    VARCHAR(100) NOT NULL,
                size         INTEGER      NOT NULL,
                content      BLOB         NOT NULL,
                thumbnail    BLOB         DEFAULT NULL,
                created_at   DATETIME     NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_issue_att_issue FOREIGN KEY (issue_id) REFERENCES issue (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_att_user FOREIGN KEY (uploaded_by_id) REFERENCES teacher (id) ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_issue_att_issue ON issue_attachment (issue_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_change (
                id         CHAR(36)    NOT NULL,
                issue_id   CHAR(36)    NOT NULL,
                author_id  CHAR(36)    DEFAULT NULL,
                field      VARCHAR(40) NOT NULL,
                old_value  CLOB        DEFAULT NULL,
                new_value  CLOB        DEFAULT NULL,
                created_at DATETIME    NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_issue_change_issue FOREIGN KEY (issue_id) REFERENCES issue (id) ON DELETE CASCADE,
                CONSTRAINT fk_issue_change_author FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_issue_change_issue ON issue_change (issue_id)');

        // Los ids van como parámetro con los 16 bytes del UUID, que es como los envía Doctrine en SQLite (ver la migración 050).
        $this->addSql(
            "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            (?, 'features.issues', 'boolean', 'true', 1, 1, 0, NULL, NULL, NULL, 'settings.category.features', 6, 70)",
            [Uuid::v4()->toBinary()]
        );
        foreach (self::EVENTS as $index => $event) {
            $this->addSql(
                "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (?, ?, 'boolean', 'true', 1, 1, 1, NULL, NULL, NULL, 'settings.category.email_receive_issues', 56, ?)",
                [Uuid::v4()->toBinary(), 'notifications.receive_' . $event, ($index + 1) * 10]
            );
        }

        // Prioridades por defecto en los centros que ya existen (los nuevos las reciben de IssuePrioritySeeder).
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM educational_centre') as $centreId) {
            foreach (self::PRIORITIES as $position => [$name, $color, $max]) {
                $this->addSql(
                    'INSERT INTO issue_priority (id, educational_centre_id, name, color, position, recommended_days, max_days) VALUES (?, ?, ?, ?, ?, 0, ?)',
                    [Uuid::v7()->toBinary(), $centreId, $name, $color, $position, $max],
                    [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $keys = ['features.issues', ...array_map(static fn (string $event): string => 'notifications.receive_' . $event, self::EVENTS)];
        foreach ($keys as $key) {
            foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
                $this->addSql("DELETE FROM $table WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
            }
            $this->addSql("DELETE FROM setting_definition WHERE key = '$key'");
        }

        foreach (['issue_change', 'issue_attachment', 'issue_observation', 'issue', 'issue_priority', 'issue_type_manager', 'issue_type'] as $table) {
            $this->addSql("DROP TABLE $table");
        }
    }
}
