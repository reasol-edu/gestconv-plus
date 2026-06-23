<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Esquema inicial (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        // educational_centre sin FK circular (se añade después de crear academic_year)
        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre (
                id                      BINARY(16)   NOT NULL,
                active_academic_year_id BINARY(16)   DEFAULT NULL,
                code                    VARCHAR(8)   NOT NULL,
                name                    VARCHAR(255) NOT NULL,
                city                    VARCHAR(255) NOT NULL,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_educational_centre_code ON educational_centre (code)');
        $this->addSql('CREATE INDEX IDX_educational_centre_active_year ON educational_centre (active_academic_year_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE academic_year (
                id                    BINARY(16)  NOT NULL,
                educational_centre_id BINARY(16)  NOT NULL,
                name                  VARCHAR(50) NOT NULL,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_uq_academic_year_centre ON academic_year (name, educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_academic_year_centre ON academic_year (educational_centre_id)');
        $this->addSql('ALTER TABLE academic_year ADD CONSTRAINT FK_ay_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id)');

        // Añadir FK circular ahora que academic_year existe
        $this->addSql('ALTER TABLE educational_centre ADD CONSTRAINT FK_ec_active_year FOREIGN KEY (active_academic_year_id) REFERENCES academic_year(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE teacher (
                id                                   BINARY(16)   NOT NULL,
                name_first_name                      VARCHAR(255) NOT NULL,
                name_last_name                       VARCHAR(255) NOT NULL,
                username                             VARCHAR(180) NOT NULL,
                admin                                TINYINT(1)   NOT NULL,
                password                             VARCHAR(255) DEFAULT NULL,
                external                             TINYINT(1)   NOT NULL,
                active                               TINYINT(1)   NOT NULL,
                email                                VARCHAR(180) DEFAULT NULL,
                pending_email                        VARCHAR(180) DEFAULT NULL,
                email_verification_token             VARCHAR(64)  DEFAULT NULL,
                email_verification_token_expires_at  DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                password_reset_token                 VARCHAR(64)  DEFAULT NULL,
                password_reset_token_expires_at      DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_username ON teacher (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_email_verification_token ON teacher (email_verification_token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_password_reset_token ON teacher (password_reset_token)');

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_admins (
                educational_centre_id BINARY(16) NOT NULL,
                teacher_id            BINARY(16) NOT NULL,
                PRIMARY KEY(educational_centre_id, teacher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_eca_centre  ON educational_centre_admins (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_eca_teacher ON educational_centre_admins (teacher_id)');
        $this->addSql('ALTER TABLE educational_centre_admins ADD CONSTRAINT FK_eca_centre  FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_admins ADD CONSTRAINT FK_eca_teacher FOREIGN KEY (teacher_id)            REFERENCES teacher(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE teacher_academic_year (
                academic_year_id BINARY(16) NOT NULL,
                teacher_id       BINARY(16) NOT NULL,
                PRIMARY KEY(academic_year_id, teacher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_tay_year    ON teacher_academic_year (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_tay_teacher ON teacher_academic_year (teacher_id)');
        $this->addSql('ALTER TABLE teacher_academic_year ADD CONSTRAINT FK_tay_year    FOREIGN KEY (academic_year_id) REFERENCES academic_year(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_academic_year ADD CONSTRAINT FK_tay_teacher FOREIGN KEY (teacher_id)       REFERENCES teacher(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE professional_family (
                id               BINARY(16)   NOT NULL,
                academic_year_id BINARY(16)   NOT NULL,
                head_id          BINARY(16)   DEFAULT NULL,
                name             VARCHAR(255) NOT NULL,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_pf_year ON professional_family (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_pf_head ON professional_family (head_id)');
        $this->addSql('ALTER TABLE professional_family ADD CONSTRAINT FK_pf_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)');
        $this->addSql('ALTER TABLE professional_family ADD CONSTRAINT FK_pf_head FOREIGN KEY (head_id)          REFERENCES teacher(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE programme (
                id                     BINARY(16)   NOT NULL,
                academic_year_id       BINARY(16)   NOT NULL,
                professional_family_id BINARY(16)   NOT NULL,
                name                   VARCHAR(255) NOT NULL,
                details                LONGTEXT     DEFAULT NULL,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_prog_year   ON programme (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_prog_family ON programme (professional_family_id)');
        $this->addSql('ALTER TABLE programme ADD CONSTRAINT FK_prog_year   FOREIGN KEY (academic_year_id)       REFERENCES academic_year(id)');
        $this->addSql('ALTER TABLE programme ADD CONSTRAINT FK_prog_family FOREIGN KEY (professional_family_id) REFERENCES professional_family(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE programme_year (
                id           BINARY(16)   NOT NULL,
                programme_id BINARY(16)   NOT NULL,
                name         VARCHAR(255) NOT NULL,
                details      LONGTEXT     DEFAULT NULL,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_py_programme ON programme_year (programme_id)');
        $this->addSql('ALTER TABLE programme_year ADD CONSTRAINT FK_py_programme FOREIGN KEY (programme_id) REFERENCES programme(id)');

        // `group` es palabra reservada en MySQL → usar backticks
        $this->addSql(<<<'SQL'
            CREATE TABLE `group` (
                id                BINARY(16)   NOT NULL,
                programme_year_id BINARY(16)   NOT NULL,
                name              VARCHAR(255) NOT NULL,
                details           LONGTEXT     DEFAULT NULL,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_group_py ON `group` (programme_year_id)');
        $this->addSql('ALTER TABLE `group` ADD CONSTRAINT FK_group_py FOREIGN KEY (programme_year_id) REFERENCES programme_year(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE group_teacher (
                group_id   BINARY(16) NOT NULL,
                teacher_id BINARY(16) NOT NULL,
                PRIMARY KEY(group_id, teacher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_gt_group   ON group_teacher (group_id)');
        $this->addSql('CREATE INDEX IDX_gt_teacher ON group_teacher (teacher_id)');
        $this->addSql('ALTER TABLE group_teacher ADD CONSTRAINT FK_gt_group   FOREIGN KEY (group_id)   REFERENCES `group`(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_teacher ADD CONSTRAINT FK_gt_teacher FOREIGN KEY (teacher_id) REFERENCES teacher(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE group_tutor (
                group_id   BINARY(16) NOT NULL,
                teacher_id BINARY(16) NOT NULL,
                PRIMARY KEY(group_id, teacher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_gtu_group   ON group_tutor (group_id)');
        $this->addSql('CREATE INDEX IDX_gtu_teacher ON group_tutor (teacher_id)');
        $this->addSql('ALTER TABLE group_tutor ADD CONSTRAINT FK_gtu_group   FOREIGN KEY (group_id)   REFERENCES `group`(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_tutor ADD CONSTRAINT FK_gtu_teacher FOREIGN KEY (teacher_id) REFERENCES teacher(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE student (
                id              BINARY(16)   NOT NULL,
                name_first_name VARCHAR(255) NOT NULL,
                name_last_name  VARCHAR(255) NOT NULL,
                student_id      VARCHAR(50)  NOT NULL,
                details         VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE student_groups (
                student_id BINARY(16) NOT NULL,
                group_id   BINARY(16) NOT NULL,
                PRIMARY KEY(student_id, group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_sg_student ON student_groups (student_id)');
        $this->addSql('CREATE INDEX IDX_sg_group   ON student_groups (group_id)');
        $this->addSql('ALTER TABLE student_groups ADD CONSTRAINT FK_sg_student FOREIGN KEY (student_id) REFERENCES student(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE student_groups ADD CONSTRAINT FK_sg_group   FOREIGN KEY (group_id)   REFERENCES `group`(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE setting_definition (
                id            BINARY(16)   NOT NULL,
                `key`         VARCHAR(100) NOT NULL,
                type          VARCHAR(255) NOT NULL,
                default_value VARCHAR(255) NOT NULL,
                global_scope  TINYINT(1)   NOT NULL DEFAULT 0,
                centre_scope  TINYINT(1)   NOT NULL DEFAULT 0,
                teacher_scope TINYINT(1)   NOT NULL DEFAULT 0,
                min_value     INT          DEFAULT NULL,
                max_value     INT          DEFAULT NULL,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_setting_definition_key ON setting_definition (`key`)');

        $this->addSql(<<<'SQL'
            CREATE TABLE global_setting_value (
                id            BINARY(16)   NOT NULL,
                definition_id BINARY(16)   NOT NULL,
                value         VARCHAR(255) NOT NULL,
                locked        TINYINT(1)   NOT NULL DEFAULT 0,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_global_setting_definition ON global_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_gsv_definition ON global_setting_value (definition_id)');
        $this->addSql('ALTER TABLE global_setting_value ADD CONSTRAINT FK_gsv_definition FOREIGN KEY (definition_id) REFERENCES setting_definition(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE centre_setting_value (
                id            BINARY(16)   NOT NULL,
                definition_id BINARY(16)   NOT NULL,
                centre_id     BINARY(16)   NOT NULL,
                value         VARCHAR(255) NOT NULL,
                locked        TINYINT(1)   NOT NULL DEFAULT 0,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_centre_setting_def_centre ON centre_setting_value (definition_id, centre_id)');
        $this->addSql('CREATE INDEX IDX_csv_definition ON centre_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_csv_centre     ON centre_setting_value (centre_id)');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_csv_definition FOREIGN KEY (definition_id) REFERENCES setting_definition(id)');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_csv_centre     FOREIGN KEY (centre_id)     REFERENCES educational_centre(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE teacher_setting_value (
                id            BINARY(16)   NOT NULL,
                definition_id BINARY(16)   NOT NULL,
                teacher_id    BINARY(16)   NOT NULL,
                value         VARCHAR(255) NOT NULL,
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_setting_def_teacher ON teacher_setting_value (definition_id, teacher_id)');
        $this->addSql('CREATE INDEX IDX_tsv_definition ON teacher_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_tsv_teacher    ON teacher_setting_value (teacher_id)');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_tsv_definition FOREIGN KEY (definition_id) REFERENCES setting_definition(id)');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_tsv_teacher    FOREIGN KEY (teacher_id)    REFERENCES teacher(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE activity_log (
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                active_user_id   BINARY(16)   DEFAULT NULL,
                real_user_id     BINARY(16)   DEFAULT NULL,
                academic_year_id BINARY(16)   DEFAULT NULL,
                created_at       DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                ip               VARCHAR(45)  NOT NULL,
                action_type      VARCHAR(100) NOT NULL,
                data             JSON         DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX idx_al_created      ON activity_log (created_at)');
        $this->addSql('CREATE INDEX idx_al_user_created ON activity_log (active_user_id, created_at)');
        $this->addSql('CREATE INDEX idx_al_type_created ON activity_log (action_type, created_at)');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT fk_al_active_user   FOREIGN KEY (active_user_id)   REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT fk_al_real_user     FOREIGN KEY (real_user_id)     REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT fk_al_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE SET NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id           BIGINT       AUTO_INCREMENT NOT NULL,
                body         LONGTEXT     NOT NULL,
                headers      LONGTEXT     NOT NULL,
                queue_name   VARCHAR(190) NOT NULL,
                created_at   DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                available_at DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                delivered_at DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        // Romper la FK circular antes de eliminar tablas
        $this->addSql('ALTER TABLE educational_centre DROP FOREIGN KEY FK_ec_active_year');

        // Eliminar en orden inverso de dependencias (hojas primero)
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE activity_log');
        $this->addSql('DROP TABLE teacher_setting_value');
        $this->addSql('DROP TABLE centre_setting_value');
        $this->addSql('DROP TABLE global_setting_value');
        $this->addSql('DROP TABLE setting_definition');
        $this->addSql('DROP TABLE student_groups');
        $this->addSql('DROP TABLE student');
        $this->addSql('DROP TABLE group_tutor');
        $this->addSql('DROP TABLE group_teacher');
        $this->addSql('DROP TABLE `group`');
        $this->addSql('DROP TABLE programme_year');
        $this->addSql('DROP TABLE programme');
        $this->addSql('DROP TABLE professional_family');
        $this->addSql('DROP TABLE teacher_academic_year');
        $this->addSql('DROP TABLE educational_centre_admins');
        $this->addSql('DROP TABLE academic_year');
        $this->addSql('DROP TABLE teacher');
        $this->addSql('DROP TABLE educational_centre');
    }
}
