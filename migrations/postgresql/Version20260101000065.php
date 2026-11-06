<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
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
        return 'Módulo de incidencias: tipos jerárquicos con responsables, prioridades, incidencias con observaciones, adjuntos e historial, el ajuste features.issues y los avisos notifications.receive_issue_* (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_type (
                id                    UUID         NOT NULL,
                educational_centre_id UUID         NOT NULL,
                parent_id             UUID         DEFAULT NULL,
                name                  VARCHAR(255) NOT NULL,
                active                BOOLEAN      NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_issue_type_centre ON issue_type (educational_centre_id)');
        $this->addSql('CREATE INDEX idx_issue_type_parent ON issue_type (parent_id)');
        $this->addSql('ALTER TABLE issue_type ADD CONSTRAINT fk_issue_type_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue_type ADD CONSTRAINT fk_issue_type_parent FOREIGN KEY (parent_id) REFERENCES issue_type (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_type_manager (
                issue_type_id UUID NOT NULL,
                teacher_id    UUID NOT NULL,
                PRIMARY KEY (issue_type_id, teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_itm_type ON issue_type_manager (issue_type_id)');
        $this->addSql('CREATE INDEX idx_itm_teacher ON issue_type_manager (teacher_id)');
        $this->addSql('ALTER TABLE issue_type_manager ADD CONSTRAINT fk_itm_type FOREIGN KEY (issue_type_id) REFERENCES issue_type (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue_type_manager ADD CONSTRAINT fk_itm_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_priority (
                id                    UUID         NOT NULL,
                educational_centre_id UUID         NOT NULL,
                name                  VARCHAR(100) NOT NULL,
                color                 VARCHAR(7)   NOT NULL,
                position              INT          NOT NULL,
                recommended_days      INT          NOT NULL DEFAULT 0,
                max_days              INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_issue_priority_centre ON issue_priority (educational_centre_id)');
        $this->addSql('ALTER TABLE issue_priority ADD CONSTRAINT fk_issue_priority_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue (
                id                    UUID        NOT NULL,
                educational_centre_id UUID        NOT NULL,
                academic_year_id      UUID        NOT NULL,
                number                INT         NOT NULL,
                type_id               UUID        NOT NULL,
                location_id           UUID        DEFAULT NULL,
                booking_id            UUID        DEFAULT NULL,
                reporter_id           UUID        NOT NULL,
                description           TEXT        NOT NULL,
                status                VARCHAR(20) NOT NULL,
                priority_id           UUID        DEFAULT NULL,
                duplicate_of_id       UUID        DEFAULT NULL,
                created_at            TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at            TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                closed_at             TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                recommended_due       DATE        DEFAULT NULL,
                max_due               DATE        DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_issue_centre_number ON issue (educational_centre_id, number)');
        $this->addSql('CREATE INDEX idx_issue_status ON issue (status)');
        $this->addSql('CREATE INDEX idx_issue_created ON issue (created_at)');
        $this->addSql('CREATE INDEX idx_issue_year ON issue (academic_year_id)');
        $this->addSql('CREATE INDEX idx_issue_type_ref ON issue (type_id)');
        $this->addSql('CREATE INDEX idx_issue_location ON issue (location_id)');
        $this->addSql('CREATE INDEX idx_issue_reporter ON issue (reporter_id)');
        $this->addSql('CREATE INDEX idx_issue_booking ON issue (booking_id)');
        $this->addSql('CREATE INDEX idx_issue_priority_ref ON issue (priority_id)');
        $this->addSql('CREATE INDEX idx_issue_duplicate ON issue (duplicate_of_id)');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT fk_issue_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT fk_issue_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT fk_issue_type FOREIGN KEY (type_id) REFERENCES issue_type (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT fk_issue_location FOREIGN KEY (location_id) REFERENCES location_option (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT fk_issue_booking FOREIGN KEY (booking_id) REFERENCES resource_booking (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT fk_issue_reporter FOREIGN KEY (reporter_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT fk_issue_priority FOREIGN KEY (priority_id) REFERENCES issue_priority (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT fk_issue_duplicate FOREIGN KEY (duplicate_of_id) REFERENCES issue (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_observation (
                id         UUID NOT NULL,
                issue_id   UUID NOT NULL,
                author_id  UUID NOT NULL,
                content    TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_issue_obs_issue ON issue_observation (issue_id)');
        $this->addSql('CREATE INDEX idx_issue_obs_author ON issue_observation (author_id)');
        $this->addSql('ALTER TABLE issue_observation ADD CONSTRAINT fk_issue_obs_issue FOREIGN KEY (issue_id) REFERENCES issue (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue_observation ADD CONSTRAINT fk_issue_obs_author FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_attachment (
                id             UUID         NOT NULL,
                issue_id       UUID         NOT NULL,
                uploaded_by_id UUID         DEFAULT NULL,
                filename       VARCHAR(255) NOT NULL,
                mime_type      VARCHAR(100) NOT NULL,
                size           INT          NOT NULL,
                content        BYTEA        NOT NULL,
                thumbnail      BYTEA        DEFAULT NULL,
                created_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_issue_att_issue ON issue_attachment (issue_id)');
        $this->addSql('CREATE INDEX idx_issue_att_user ON issue_attachment (uploaded_by_id)');
        $this->addSql('ALTER TABLE issue_attachment ADD CONSTRAINT fk_issue_att_issue FOREIGN KEY (issue_id) REFERENCES issue (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue_attachment ADD CONSTRAINT fk_issue_att_user FOREIGN KEY (uploaded_by_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE issue_change (
                id         UUID        NOT NULL,
                issue_id   UUID        NOT NULL,
                author_id  UUID        DEFAULT NULL,
                field      VARCHAR(40) NOT NULL,
                old_value  TEXT        DEFAULT NULL,
                new_value  TEXT        DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_issue_change_issue ON issue_change (issue_id)');
        $this->addSql('CREATE INDEX idx_issue_change_author ON issue_change (author_id)');
        $this->addSql('ALTER TABLE issue_change ADD CONSTRAINT fk_issue_change_issue FOREIGN KEY (issue_id) REFERENCES issue (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE issue_change ADD CONSTRAINT fk_issue_change_author FOREIGN KEY (author_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $rows = ["(gen_random_uuid(), 'features.issues', 'boolean', 'true', TRUE, TRUE, FALSE, NULL, NULL, 'settings.category.features', 6, 70)"];
        foreach (self::EVENTS as $index => $event) {
            $rows[] = sprintf("(gen_random_uuid(), 'notifications.receive_%s', 'boolean', 'true', TRUE, TRUE, TRUE, NULL, NULL, 'settings.category.email_receive_issues', 56, %d)", $event, ($index + 1) * 10);
        }
        $this->addSql(
            'INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES '
            . implode(', ', $rows)
        );

        // Prioridades por defecto en los centros que ya existen (los nuevos las reciben de IssuePrioritySeeder).
        foreach (self::PRIORITIES as $position => [$name, $color, $max]) {
            $this->addSql(sprintf(
                "INSERT INTO issue_priority (id, educational_centre_id, name, color, position, recommended_days, max_days) SELECT gen_random_uuid(), id, '%s', '%s', %d, 0, %d FROM educational_centre",
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
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
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
