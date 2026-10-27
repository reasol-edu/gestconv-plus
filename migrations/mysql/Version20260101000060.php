<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000060 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reservas de recursos compartidos: categorías (con responsables), recursos y reservas, y los ajustes features.bookings y bookings.max_advance_days (MySQL/MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_category (
                id                      BINARY(16)   NOT NULL,
                educational_centre_id   BINARY(16)   NOT NULL,
                name                    VARCHAR(200) NOT NULL,
                color                   VARCHAR(7)   DEFAULT NULL,
                active                  TINYINT(1)   NOT NULL DEFAULT 1,
                restricted              TINYINT(1)   NOT NULL DEFAULT 0,
                requires_approval       TINYINT(1)   NOT NULL DEFAULT 0,
                max_advance_days        INT          DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_resource_category_centre (educational_centre_id),
                CONSTRAINT fk_resource_category_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_category_manager (
                resource_category_id  BINARY(16) NOT NULL,
                teacher_id            BINARY(16) NOT NULL,
                PRIMARY KEY (resource_category_id, teacher_id),
                INDEX idx_rcm_category (resource_category_id),
                INDEX idx_rcm_teacher (teacher_id),
                CONSTRAINT fk_rcm_category FOREIGN KEY (resource_category_id) REFERENCES resource_category (id) ON DELETE CASCADE,
                CONSTRAINT fk_rcm_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE shared_resource (
                id           BINARY(16)   NOT NULL,
                category_id  BINARY(16)   NOT NULL,
                name         VARCHAR(200) NOT NULL,
                description  LONGTEXT     DEFAULT NULL,
                location     VARCHAR(255) DEFAULT NULL,
                active       TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_shared_resource_category (category_id),
                CONSTRAINT fk_shared_resource_category FOREIGN KEY (category_id) REFERENCES resource_category (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_booking (
                id                BINARY(16)   NOT NULL,
                academic_year_id  BINARY(16)   NOT NULL,
                resource_id       BINARY(16)   NOT NULL,
                teacher_id        BINARY(16)   NOT NULL,
                date              DATE         NOT NULL,
                description       LONGTEXT     DEFAULT NULL,
                status            VARCHAR(16)  NOT NULL,
                rejection_reason  VARCHAR(500) DEFAULT NULL,
                series_id         BINARY(16)   DEFAULT NULL,
                resolution_seen   TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                INDEX idx_resource_booking_date (date),
                INDEX idx_resource_booking_series (series_id),
                INDEX idx_resource_booking_year (academic_year_id),
                INDEX idx_resource_booking_resource (resource_id),
                INDEX idx_resource_booking_teacher (teacher_id),
                CONSTRAINT fk_resource_booking_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id),
                CONSTRAINT fk_resource_booking_resource FOREIGN KEY (resource_id) REFERENCES shared_resource (id) ON DELETE CASCADE,
                CONSTRAINT fk_resource_booking_teacher FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_booking_time_slot (
                resource_booking_id  BINARY(16) NOT NULL,
                time_slot_id         BINARY(16) NOT NULL,
                PRIMARY KEY (resource_booking_id, time_slot_id),
                INDEX idx_rbts_booking (resource_booking_id),
                INDEX idx_rbts_slot (time_slot_id),
                CONSTRAINT fk_rbts_booking FOREIGN KEY (resource_booking_id) REFERENCES resource_booking (id) ON DELETE CASCADE,
                CONSTRAINT fk_rbts_slot FOREIGN KEY (time_slot_id) REFERENCES time_slot (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE resource_booking_group (
                resource_booking_id  BINARY(16) NOT NULL,
                group_id             BINARY(16) NOT NULL,
                PRIMARY KEY (resource_booking_id, group_id),
                INDEX idx_rbg_booking (resource_booking_id),
                INDEX idx_rbg_group (group_id),
                CONSTRAINT fk_rbg_booking FOREIGN KEY (resource_booking_id) REFERENCES resource_booking (id) ON DELETE CASCADE,
                CONSTRAINT fk_rbg_group FOREIGN KEY (group_id) REFERENCES `group` (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'features.bookings', 'boolean', 'true', 1, 1, 0, NULL, NULL, NULL, 'settings.category.features', 6, 60),
                (UNHEX(REPLACE(UUID(), '-', '')), 'bookings.max_advance_days', 'integer', '0', 1, 1, 0, 0, 365, NULL, 'settings.category.bookings', 37, 10)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        foreach (['features.bookings', 'bookings.max_advance_days'] as $key) {
            $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` = '$key')");
            $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE `key` = '$key')");
            $this->addSql("DELETE FROM setting_definition WHERE `key` = '$key'");
        }

        $this->addSql('DROP TABLE resource_booking_group');
        $this->addSql('DROP TABLE resource_booking_time_slot');
        $this->addSql('DROP TABLE resource_booking');
        $this->addSql('DROP TABLE shared_resource');
        $this->addSql('DROP TABLE resource_category_manager');
        $this->addSql('DROP TABLE resource_category');
    }
}
