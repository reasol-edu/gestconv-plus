<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Esquema inicial (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // SQLite permite declarar FKs inline aunque la tabla referenciada no exista aún
        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre (
                id                      CHAR(36)     NOT NULL,
                active_academic_year_id CHAR(36)     DEFAULT NULL,
                code                    VARCHAR(8)   NOT NULL,
                name                    VARCHAR(255) NOT NULL,
                city                    VARCHAR(255) NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_ec_active_year FOREIGN KEY (active_academic_year_id) REFERENCES academic_year(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_educational_centre_code ON educational_centre (code)');
        $this->addSql('CREATE INDEX IDX_educational_centre_active_year ON educational_centre (active_academic_year_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE academic_year (
                id                    CHAR(36)    NOT NULL,
                educational_centre_id CHAR(36)    NOT NULL,
                name                  VARCHAR(50) NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_ay_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_uq_academic_year_centre ON academic_year (name, educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_academic_year_centre ON academic_year (educational_centre_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE teacher (
                id                                   CHAR(36)     NOT NULL,
                name_first_name                      VARCHAR(255) NOT NULL,
                name_last_name                       VARCHAR(255) NOT NULL,
                username                             VARCHAR(180) NOT NULL,
                admin                                BOOLEAN      NOT NULL,
                password                             VARCHAR(255) DEFAULT NULL,
                external                             BOOLEAN      NOT NULL,
                active                               BOOLEAN      NOT NULL,
                email                                VARCHAR(180) DEFAULT NULL,
                pending_email                        VARCHAR(180) DEFAULT NULL,
                email_verification_token             VARCHAR(64)  DEFAULT NULL,
                email_verification_token_expires_at  DATETIME     DEFAULT NULL,
                password_reset_token                 VARCHAR(64)  DEFAULT NULL,
                password_reset_token_expires_at      DATETIME     DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_username ON teacher (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_email_verification_token ON teacher (email_verification_token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_password_reset_token ON teacher (password_reset_token)');

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_admins (
                educational_centre_id CHAR(36) NOT NULL,
                teacher_id            CHAR(36) NOT NULL,
                PRIMARY KEY(educational_centre_id, teacher_id),
                CONSTRAINT FK_eca_centre  FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id) ON DELETE CASCADE,
                CONSTRAINT FK_eca_teacher FOREIGN KEY (teacher_id)            REFERENCES teacher(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_eca_centre  ON educational_centre_admins (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_eca_teacher ON educational_centre_admins (teacher_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE teacher_academic_year (
                academic_year_id CHAR(36) NOT NULL,
                teacher_id       CHAR(36) NOT NULL,
                PRIMARY KEY(academic_year_id, teacher_id),
                CONSTRAINT FK_tay_year    FOREIGN KEY (academic_year_id) REFERENCES academic_year(id) ON DELETE CASCADE,
                CONSTRAINT FK_tay_teacher FOREIGN KEY (teacher_id)       REFERENCES teacher(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_tay_year    ON teacher_academic_year (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_tay_teacher ON teacher_academic_year (teacher_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE programme (
                id               CHAR(36)     NOT NULL,
                academic_year_id CHAR(36)     NOT NULL,
                name             VARCHAR(255) NOT NULL,
                details          CLOB         DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_prog_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_prog_year ON programme (academic_year_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE programme_year (
                id           CHAR(36)     NOT NULL,
                programme_id CHAR(36)     NOT NULL,
                name         VARCHAR(255) NOT NULL,
                details      CLOB         DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_py_programme FOREIGN KEY (programme_id) REFERENCES programme(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_py_programme ON programme_year (programme_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE "group" (
                id                CHAR(36)     NOT NULL,
                programme_year_id CHAR(36)     NOT NULL,
                name              VARCHAR(255) NOT NULL,
                details           CLOB         DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_group_py FOREIGN KEY (programme_year_id) REFERENCES programme_year(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_group_py ON "group" (programme_year_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE group_teacher (
                group_id   CHAR(36) NOT NULL,
                teacher_id CHAR(36) NOT NULL,
                PRIMARY KEY(group_id, teacher_id),
                CONSTRAINT FK_gt_group   FOREIGN KEY (group_id)   REFERENCES "group"(id) ON DELETE CASCADE,
                CONSTRAINT FK_gt_teacher FOREIGN KEY (teacher_id) REFERENCES teacher(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_gt_group   ON group_teacher (group_id)');
        $this->addSql('CREATE INDEX IDX_gt_teacher ON group_teacher (teacher_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE group_tutor (
                group_id   CHAR(36) NOT NULL,
                teacher_id CHAR(36) NOT NULL,
                PRIMARY KEY(group_id, teacher_id),
                CONSTRAINT FK_gtu_group   FOREIGN KEY (group_id)   REFERENCES "group"(id) ON DELETE CASCADE,
                CONSTRAINT FK_gtu_teacher FOREIGN KEY (teacher_id) REFERENCES teacher(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_gtu_group   ON group_tutor (group_id)');
        $this->addSql('CREATE INDEX IDX_gtu_teacher ON group_tutor (teacher_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE student (
                id              CHAR(36)     NOT NULL,
                name_first_name VARCHAR(255) NOT NULL,
                name_last_name  VARCHAR(255) NOT NULL,
                student_id      VARCHAR(50)  NOT NULL,
                details         VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE student_groups (
                student_id CHAR(36) NOT NULL,
                group_id   CHAR(36) NOT NULL,
                PRIMARY KEY(student_id, group_id),
                CONSTRAINT FK_sg_student FOREIGN KEY (student_id) REFERENCES student(id) ON DELETE CASCADE,
                CONSTRAINT FK_sg_group   FOREIGN KEY (group_id)   REFERENCES "group"(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_sg_student ON student_groups (student_id)');
        $this->addSql('CREATE INDEX IDX_sg_group   ON student_groups (group_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE setting_definition (
                id            CHAR(36)     NOT NULL,
                key           VARCHAR(100) NOT NULL,
                type          VARCHAR(255) NOT NULL,
                default_value VARCHAR(255) NOT NULL,
                global_scope  BOOLEAN      NOT NULL DEFAULT 0,
                centre_scope  BOOLEAN      NOT NULL DEFAULT 0,
                teacher_scope BOOLEAN      NOT NULL DEFAULT 0,
                min_value     INTEGER      DEFAULT NULL,
                max_value     INTEGER      DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_setting_definition_key ON setting_definition (key)');

        $this->addSql(<<<'SQL'
            CREATE TABLE global_setting_value (
                id            CHAR(36)     NOT NULL,
                definition_id CHAR(36)     NOT NULL,
                value         VARCHAR(255) NOT NULL,
                locked        BOOLEAN      NOT NULL DEFAULT 0,
                PRIMARY KEY(id),
                CONSTRAINT FK_gsv_definition FOREIGN KEY (definition_id) REFERENCES setting_definition(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_global_setting_definition ON global_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_gsv_definition ON global_setting_value (definition_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE centre_setting_value (
                id            CHAR(36)     NOT NULL,
                definition_id CHAR(36)     NOT NULL,
                centre_id     CHAR(36)     NOT NULL,
                value         VARCHAR(255) NOT NULL,
                locked        BOOLEAN      NOT NULL DEFAULT 0,
                PRIMARY KEY(id),
                CONSTRAINT FK_csv_definition FOREIGN KEY (definition_id) REFERENCES setting_definition(id),
                CONSTRAINT FK_csv_centre     FOREIGN KEY (centre_id)     REFERENCES educational_centre(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_centre_setting_def_centre ON centre_setting_value (definition_id, centre_id)');
        $this->addSql('CREATE INDEX IDX_csv_definition ON centre_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_csv_centre     ON centre_setting_value (centre_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE teacher_setting_value (
                id            CHAR(36)     NOT NULL,
                definition_id CHAR(36)     NOT NULL,
                teacher_id    CHAR(36)     NOT NULL,
                value         VARCHAR(255) NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_tsv_definition FOREIGN KEY (definition_id) REFERENCES setting_definition(id),
                CONSTRAINT FK_tsv_teacher    FOREIGN KEY (teacher_id)    REFERENCES teacher(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_setting_def_teacher ON teacher_setting_value (definition_id, teacher_id)');
        $this->addSql('CREATE INDEX IDX_tsv_definition ON teacher_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_tsv_teacher    ON teacher_setting_value (teacher_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE activity_log (
                id               INTEGER      NOT NULL PRIMARY KEY AUTOINCREMENT,
                active_user_id   CLOB         DEFAULT NULL,
                real_user_id     CLOB         DEFAULT NULL,
                academic_year_id CLOB         DEFAULT NULL,
                created_at       DATETIME     NOT NULL,
                ip               VARCHAR(45)  NOT NULL,
                action_type      VARCHAR(100) NOT NULL,
                data             CLOB         DEFAULT NULL,
                CONSTRAINT fk_al_active_user   FOREIGN KEY (active_user_id)   REFERENCES teacher (id) ON DELETE SET NULL,
                CONSTRAINT fk_al_real_user     FOREIGN KEY (real_user_id)     REFERENCES teacher (id) ON DELETE SET NULL,
                CONSTRAINT fk_al_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_al_created      ON activity_log (created_at)');
        $this->addSql('CREATE INDEX idx_al_user_created ON activity_log (active_user_id, created_at)');
        $this->addSql('CREATE INDEX idx_al_type_created ON activity_log (action_type, created_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id           INTEGER      NOT NULL PRIMARY KEY AUTOINCREMENT,
                body         CLOB         NOT NULL,
                headers      CLOB         NOT NULL,
                queue_name   VARCHAR(190) NOT NULL,
                created_at   DATETIME     NOT NULL,
                available_at DATETIME     NOT NULL,
                delivered_at DATETIME     DEFAULT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
        $this->addSql('DROP TABLE IF EXISTS activity_log');
        $this->addSql('DROP TABLE IF EXISTS teacher_setting_value');
        $this->addSql('DROP TABLE IF EXISTS centre_setting_value');
        $this->addSql('DROP TABLE IF EXISTS global_setting_value');
        $this->addSql('DROP TABLE IF EXISTS setting_definition');
        $this->addSql('DROP TABLE IF EXISTS student_groups');
        $this->addSql('DROP TABLE IF EXISTS student');
        $this->addSql('DROP TABLE IF EXISTS group_tutor');
        $this->addSql('DROP TABLE IF EXISTS group_teacher');
        $this->addSql('DROP TABLE IF EXISTS "group"');
        $this->addSql('DROP TABLE IF EXISTS programme_year');
        $this->addSql('DROP TABLE IF EXISTS programme');
        $this->addSql('DROP TABLE IF EXISTS teacher_academic_year');
        $this->addSql('DROP TABLE IF EXISTS educational_centre_admins');
        $this->addSql('DROP TABLE IF EXISTS academic_year');
        $this->addSql('DROP TABLE IF EXISTS teacher');
        $this->addSql('DROP TABLE IF EXISTS educational_centre');
    }
}
