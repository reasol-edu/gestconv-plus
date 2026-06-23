<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Esquema inicial (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        // educational_centre sin FK circular (se añade después de crear academic_year)
        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre (
                id                      UUID         NOT NULL,
                active_academic_year_id UUID         DEFAULT NULL,
                code                    VARCHAR(8)   NOT NULL,
                name                    VARCHAR(255) NOT NULL,
                city                    VARCHAR(255) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_educational_centre_code ON educational_centre (code)');
        $this->addSql('CREATE INDEX IDX_educational_centre_active_year ON educational_centre (active_academic_year_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE academic_year (
                id                    UUID        NOT NULL,
                educational_centre_id UUID        NOT NULL,
                name                  VARCHAR(50) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_uq_academic_year_centre ON academic_year (name, educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_academic_year_centre ON academic_year (educational_centre_id)');
        $this->addSql('ALTER TABLE academic_year ADD CONSTRAINT FK_ay_centre FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Añadir FK circular ahora que academic_year existe
        $this->addSql('ALTER TABLE educational_centre ADD CONSTRAINT FK_ec_active_year FOREIGN KEY (active_academic_year_id) REFERENCES academic_year(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE teacher (
                id                                   UUID         NOT NULL,
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
                email_verification_token_expires_at  TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                password_reset_token                 VARCHAR(64)  DEFAULT NULL,
                password_reset_token_expires_at      TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_username ON teacher (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_email_verification_token ON teacher (email_verification_token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_password_reset_token ON teacher (password_reset_token)');
        $this->addSql("COMMENT ON COLUMN teacher.email_verification_token_expires_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN teacher.password_reset_token_expires_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE educational_centre_admins (
                educational_centre_id UUID NOT NULL,
                teacher_id            UUID NOT NULL,
                PRIMARY KEY(educational_centre_id, teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_eca_centre  ON educational_centre_admins (educational_centre_id)');
        $this->addSql('CREATE INDEX IDX_eca_teacher ON educational_centre_admins (teacher_id)');
        $this->addSql('ALTER TABLE educational_centre_admins ADD CONSTRAINT FK_eca_centre  FOREIGN KEY (educational_centre_id) REFERENCES educational_centre(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE educational_centre_admins ADD CONSTRAINT FK_eca_teacher FOREIGN KEY (teacher_id)            REFERENCES teacher(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE teacher_academic_year (
                academic_year_id UUID NOT NULL,
                teacher_id       UUID NOT NULL,
                PRIMARY KEY(academic_year_id, teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_tay_year    ON teacher_academic_year (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_tay_teacher ON teacher_academic_year (teacher_id)');
        $this->addSql('ALTER TABLE teacher_academic_year ADD CONSTRAINT FK_tay_year    FOREIGN KEY (academic_year_id) REFERENCES academic_year(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_academic_year ADD CONSTRAINT FK_tay_teacher FOREIGN KEY (teacher_id)       REFERENCES teacher(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE professional_family (
                id               UUID         NOT NULL,
                academic_year_id UUID         NOT NULL,
                head_id          UUID         DEFAULT NULL,
                name             VARCHAR(255) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_pf_year ON professional_family (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_pf_head ON professional_family (head_id)');
        $this->addSql('ALTER TABLE professional_family ADD CONSTRAINT FK_pf_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE professional_family ADD CONSTRAINT FK_pf_head FOREIGN KEY (head_id)          REFERENCES teacher(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE programme (
                id                     UUID         NOT NULL,
                academic_year_id       UUID         NOT NULL,
                professional_family_id UUID         NOT NULL,
                name                   VARCHAR(255) NOT NULL,
                details                TEXT         DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_prog_year   ON programme (academic_year_id)');
        $this->addSql('CREATE INDEX IDX_prog_family ON programme (professional_family_id)');
        $this->addSql('ALTER TABLE programme ADD CONSTRAINT FK_prog_year   FOREIGN KEY (academic_year_id)       REFERENCES academic_year(id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE programme ADD CONSTRAINT FK_prog_family FOREIGN KEY (professional_family_id) REFERENCES professional_family(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE programme_year (
                id           UUID         NOT NULL,
                programme_id UUID         NOT NULL,
                name         VARCHAR(255) NOT NULL,
                details      TEXT         DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_py_programme ON programme_year (programme_id)');
        $this->addSql('ALTER TABLE programme_year ADD CONSTRAINT FK_py_programme FOREIGN KEY (programme_id) REFERENCES programme(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE "group" (
                id                UUID         NOT NULL,
                programme_year_id UUID         NOT NULL,
                name              VARCHAR(255) NOT NULL,
                details           TEXT         DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_group_py ON "group" (programme_year_id)');
        $this->addSql('ALTER TABLE "group" ADD CONSTRAINT FK_group_py FOREIGN KEY (programme_year_id) REFERENCES programme_year(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE group_teacher (
                group_id   UUID NOT NULL,
                teacher_id UUID NOT NULL,
                PRIMARY KEY(group_id, teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_gt_group   ON group_teacher (group_id)');
        $this->addSql('CREATE INDEX IDX_gt_teacher ON group_teacher (teacher_id)');
        $this->addSql('ALTER TABLE group_teacher ADD CONSTRAINT FK_gt_group   FOREIGN KEY (group_id)   REFERENCES "group"(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_teacher ADD CONSTRAINT FK_gt_teacher FOREIGN KEY (teacher_id) REFERENCES teacher(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE group_tutor (
                group_id   UUID NOT NULL,
                teacher_id UUID NOT NULL,
                PRIMARY KEY(group_id, teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_gtu_group   ON group_tutor (group_id)');
        $this->addSql('CREATE INDEX IDX_gtu_teacher ON group_tutor (teacher_id)');
        $this->addSql('ALTER TABLE group_tutor ADD CONSTRAINT FK_gtu_group   FOREIGN KEY (group_id)   REFERENCES "group"(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_tutor ADD CONSTRAINT FK_gtu_teacher FOREIGN KEY (teacher_id) REFERENCES teacher(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE student (
                id              UUID         NOT NULL,
                name_first_name VARCHAR(255) NOT NULL,
                name_last_name  VARCHAR(255) NOT NULL,
                student_id      VARCHAR(50)  NOT NULL,
                details         VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE student_groups (
                student_id UUID NOT NULL,
                group_id   UUID NOT NULL,
                PRIMARY KEY(student_id, group_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_sg_student ON student_groups (student_id)');
        $this->addSql('CREATE INDEX IDX_sg_group   ON student_groups (group_id)');
        $this->addSql('ALTER TABLE student_groups ADD CONSTRAINT FK_sg_student FOREIGN KEY (student_id) REFERENCES student(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE student_groups ADD CONSTRAINT FK_sg_group   FOREIGN KEY (group_id)   REFERENCES "group"(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            CREATE TABLE setting_definition (
                id            UUID         NOT NULL,
                key           VARCHAR(100) NOT NULL,
                type          VARCHAR(255) NOT NULL,
                default_value VARCHAR(255) NOT NULL,
                global_scope  BOOLEAN      NOT NULL DEFAULT FALSE,
                centre_scope  BOOLEAN      NOT NULL DEFAULT FALSE,
                teacher_scope BOOLEAN      NOT NULL DEFAULT FALSE,
                min_value     INT          DEFAULT NULL,
                max_value     INT          DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_setting_definition_key ON setting_definition (key)');

        $this->addSql(<<<'SQL'
            CREATE TABLE global_setting_value (
                id            UUID         NOT NULL,
                definition_id UUID         NOT NULL,
                value         VARCHAR(255) NOT NULL,
                locked        BOOLEAN      NOT NULL DEFAULT FALSE,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_global_setting_definition ON global_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_gsv_definition ON global_setting_value (definition_id)');
        $this->addSql('ALTER TABLE global_setting_value ADD CONSTRAINT FK_gsv_definition FOREIGN KEY (definition_id) REFERENCES setting_definition(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE centre_setting_value (
                id            UUID         NOT NULL,
                definition_id UUID         NOT NULL,
                centre_id     UUID         NOT NULL,
                value         VARCHAR(255) NOT NULL,
                locked        BOOLEAN      NOT NULL DEFAULT FALSE,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_centre_setting_def_centre ON centre_setting_value (definition_id, centre_id)');
        $this->addSql('CREATE INDEX IDX_csv_definition ON centre_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_csv_centre     ON centre_setting_value (centre_id)');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_csv_definition FOREIGN KEY (definition_id) REFERENCES setting_definition(id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE centre_setting_value ADD CONSTRAINT FK_csv_centre     FOREIGN KEY (centre_id)     REFERENCES educational_centre(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE teacher_setting_value (
                id            UUID         NOT NULL,
                definition_id UUID         NOT NULL,
                teacher_id    UUID         NOT NULL,
                value         VARCHAR(255) NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_teacher_setting_def_teacher ON teacher_setting_value (definition_id, teacher_id)');
        $this->addSql('CREATE INDEX IDX_tsv_definition ON teacher_setting_value (definition_id)');
        $this->addSql('CREATE INDEX IDX_tsv_teacher    ON teacher_setting_value (teacher_id)');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_tsv_definition FOREIGN KEY (definition_id) REFERENCES setting_definition(id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE teacher_setting_value ADD CONSTRAINT FK_tsv_teacher    FOREIGN KEY (teacher_id)    REFERENCES teacher(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE activity_log (
                id               SERIAL       NOT NULL,
                active_user_id   UUID         DEFAULT NULL,
                real_user_id     UUID         DEFAULT NULL,
                academic_year_id UUID         DEFAULT NULL,
                created_at       TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                ip               VARCHAR(45)  NOT NULL,
                action_type      VARCHAR(100) NOT NULL,
                data             JSON         DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_al_created      ON activity_log (created_at)');
        $this->addSql('CREATE INDEX idx_al_user_created ON activity_log (active_user_id, created_at)');
        $this->addSql('CREATE INDEX idx_al_type_created ON activity_log (action_type, created_at)');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT fk_al_active_user   FOREIGN KEY (active_user_id)   REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT fk_al_real_user     FOREIGN KEY (real_user_id)     REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT fk_al_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN activity_log.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id           BIGSERIAL    NOT NULL,
                body         TEXT         NOT NULL,
                headers      TEXT         NOT NULL,
                queue_name   VARCHAR(190) NOT NULL,
                created_at   TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql("COMMENT ON COLUMN messenger_messages.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN messenger_messages.available_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN messenger_messages.delivered_at IS '(DC2Type:datetime_immutable)'");

        // Notificación LISTEN/NOTIFY para el transporte Doctrine de PostgreSQL
        $this->addSql("CREATE OR REPLACE FUNCTION notify_messenger_messages() RETURNS TRIGGER AS \$\$
            BEGIN
                PERFORM pg_notify('messenger_messages', NEW.queue_name::text);
                RETURN NEW;
            END;
        \$\$ LANGUAGE plpgsql;");
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages;');
        $this->addSql('CREATE TRIGGER notify_trigger AFTER INSERT OR UPDATE ON messenger_messages FOR EACH ROW EXECUTE PROCEDURE notify_messenger_messages();');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        // Romper la FK circular antes de eliminar tablas
        $this->addSql('ALTER TABLE educational_centre DROP CONSTRAINT FK_ec_active_year');

        // Eliminar en orden inverso de dependencias (hojas primero)
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages');
        $this->addSql('DROP FUNCTION IF EXISTS notify_messenger_messages()');
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
        $this->addSql('DROP TABLE "group"');
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
