<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000060 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reservas de recursos compartidos: categorías (con responsables), recursos y reservas, y los ajustes features.bookings y bookings.max_advance_days (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_category (
                id                      UUID         NOT NULL,
                educational_centre_id   UUID         NOT NULL,
                name                    VARCHAR(200) NOT NULL,
                color                   VARCHAR(7)   DEFAULT NULL,
                active                  BOOLEAN      NOT NULL DEFAULT TRUE,
                restricted              BOOLEAN      NOT NULL DEFAULT FALSE,
                requires_approval       BOOLEAN      NOT NULL DEFAULT FALSE,
                max_advance_days        INT          DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_resource_category_centre ON resource_category (educational_centre_id)');
        $this->addSql('ALTER TABLE resource_category ADD CONSTRAINT fk_resource_category_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_category_manager (
                resource_category_id  UUID NOT NULL,
                teacher_id            UUID NOT NULL,
                PRIMARY KEY (resource_category_id, teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_rcm_category ON resource_category_manager (resource_category_id)');
        $this->addSql('CREATE INDEX idx_rcm_teacher ON resource_category_manager (teacher_id)');
        $this->addSql('ALTER TABLE resource_category_manager ADD CONSTRAINT fk_rcm_category FOREIGN KEY (resource_category_id) REFERENCES resource_category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE resource_category_manager ADD CONSTRAINT fk_rcm_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE shared_resource (
                id           UUID         NOT NULL,
                category_id  UUID         NOT NULL,
                name         VARCHAR(200) NOT NULL,
                description  TEXT         DEFAULT NULL,
                location     VARCHAR(255) DEFAULT NULL,
                active       BOOLEAN      NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_shared_resource_category ON shared_resource (category_id)');
        $this->addSql('ALTER TABLE shared_resource ADD CONSTRAINT fk_shared_resource_category FOREIGN KEY (category_id) REFERENCES resource_category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_booking (
                id                UUID         NOT NULL,
                academic_year_id  UUID         NOT NULL,
                resource_id       UUID         NOT NULL,
                teacher_id        UUID         NOT NULL,
                date              DATE         NOT NULL,
                description       TEXT         DEFAULT NULL,
                status            VARCHAR(16)  NOT NULL,
                rejection_reason  VARCHAR(500) DEFAULT NULL,
                series_id         UUID         DEFAULT NULL,
                resolution_seen   BOOLEAN      NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_resource_booking_date ON resource_booking (date)');
        $this->addSql('CREATE INDEX idx_resource_booking_series ON resource_booking (series_id)');
        $this->addSql('CREATE INDEX idx_resource_booking_year ON resource_booking (academic_year_id)');
        $this->addSql('CREATE INDEX idx_resource_booking_resource ON resource_booking (resource_id)');
        $this->addSql('CREATE INDEX idx_resource_booking_teacher ON resource_booking (teacher_id)');
        $this->addSql('ALTER TABLE resource_booking ADD CONSTRAINT fk_resource_booking_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE resource_booking ADD CONSTRAINT fk_resource_booking_resource FOREIGN KEY (resource_id) REFERENCES shared_resource (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE resource_booking ADD CONSTRAINT fk_resource_booking_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_booking_time_slot (
                resource_booking_id  UUID NOT NULL,
                time_slot_id         UUID NOT NULL,
                PRIMARY KEY (resource_booking_id, time_slot_id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_rbts_booking ON resource_booking_time_slot (resource_booking_id)');
        $this->addSql('CREATE INDEX idx_rbts_slot ON resource_booking_time_slot (time_slot_id)');
        $this->addSql('ALTER TABLE resource_booking_time_slot ADD CONSTRAINT fk_rbts_booking FOREIGN KEY (resource_booking_id) REFERENCES resource_booking (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE resource_booking_time_slot ADD CONSTRAINT fk_rbts_slot FOREIGN KEY (time_slot_id) REFERENCES time_slot (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_booking_group (
                resource_booking_id  UUID NOT NULL,
                group_id             UUID NOT NULL,
                PRIMARY KEY (resource_booking_id, group_id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_rbg_booking ON resource_booking_group (resource_booking_id)');
        $this->addSql('CREATE INDEX idx_rbg_group ON resource_booking_group (group_id)');
        $this->addSql('ALTER TABLE resource_booking_group ADD CONSTRAINT fk_rbg_booking FOREIGN KEY (resource_booking_id) REFERENCES resource_booking (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE resource_booking_group ADD CONSTRAINT fk_rbg_group FOREIGN KEY (group_id) REFERENCES "group" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (gen_random_uuid(), 'features.bookings', 'boolean', 'true', TRUE, TRUE, FALSE, NULL, NULL, NULL, 'settings.category.features', 6, 60),
                (gen_random_uuid(), 'bookings.max_advance_days', 'integer', '0', TRUE, TRUE, FALSE, 0, 365, NULL, 'settings.category.bookings', 37, 10)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        foreach (['features.bookings', 'bookings.max_advance_days'] as $key) {
            $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
            $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = '$key')");
            $this->addSql("DELETE FROM setting_definition WHERE key = '$key'");
        }

        $this->addSql('DROP TABLE resource_booking_group');
        $this->addSql('DROP TABLE resource_booking_time_slot');
        $this->addSql('DROP TABLE resource_booking');
        $this->addSql('DROP TABLE shared_resource');
        $this->addSql('DROP TABLE resource_category_manager');
        $this->addSql('DROP TABLE resource_category');
    }
}
