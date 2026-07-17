<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea absence, activity, activity_attachment y activity_group_teacher para la gestión de ausencias previstas del profesorado, y añade el ajuste de retención de adjuntos de actividades (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE absence (
                id                UUID NOT NULL,
                teacher_id        UUID NOT NULL,
                academic_year_id  UUID NOT NULL,
                start_date        DATE NOT NULL,
                end_date          DATE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_absence_teacher ON absence (teacher_id)');
        $this->addSql('CREATE INDEX IDX_absence_year    ON absence (academic_year_id)');
        $this->addSql('ALTER TABLE absence ADD CONSTRAINT FK_absence_teacher FOREIGN KEY (teacher_id)       REFERENCES teacher(id)       NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE absence ADD CONSTRAINT FK_absence_year    FOREIGN KEY (academic_year_id) REFERENCES academic_year(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE activity (
                id            UUID         NOT NULL,
                absence_id    UUID         NOT NULL,
                time_slot_id  UUID         NOT NULL,
                date          DATE         NOT NULL,
                description   TEXT         NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_activity_absence   ON activity (absence_id)');
        $this->addSql('CREATE INDEX IDX_activity_time_slot ON activity (time_slot_id)');
        $this->addSql('ALTER TABLE activity ADD CONSTRAINT FK_activity_absence   FOREIGN KEY (absence_id)   REFERENCES absence(id)   ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE activity ADD CONSTRAINT FK_activity_time_slot FOREIGN KEY (time_slot_id) REFERENCES time_slot(id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE activity_attachment (
                id           UUID          NOT NULL,
                activity_id  UUID          NOT NULL,
                filename     VARCHAR(255)  NOT NULL,
                mime_type    VARCHAR(100)  NOT NULL,
                size         INT           NOT NULL,
                content      BYTEA         NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_activity_attachment_activity ON activity_attachment (activity_id)');
        $this->addSql('ALTER TABLE activity_attachment ADD CONSTRAINT FK_activity_attachment_activity FOREIGN KEY (activity_id) REFERENCES activity(id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE activity_group_teacher (
                activity_id       UUID NOT NULL,
                group_teacher_id  UUID NOT NULL,
                PRIMARY KEY(activity_id, group_teacher_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_agt_activity      ON activity_group_teacher (activity_id)');
        $this->addSql('CREATE INDEX IDX_agt_group_teacher ON activity_group_teacher (group_teacher_id)');
        $this->addSql('ALTER TABLE activity_group_teacher ADD CONSTRAINT FK_agt_activity      FOREIGN KEY (activity_id)      REFERENCES activity(id)      ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE activity_group_teacher ADD CONSTRAINT FK_agt_group_teacher FOREIGN KEY (group_teacher_id) REFERENCES group_teacher(id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (gen_random_uuid(), 'absences.attachment_retention_days', 'integer', '7', TRUE, TRUE, FALSE, 0, 3650, 'settings.category.absences', 70, 10)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key = 'absences.attachment_retention_days'");

        $this->addSql('ALTER TABLE activity_group_teacher DROP CONSTRAINT FK_agt_activity');
        $this->addSql('ALTER TABLE activity_group_teacher DROP CONSTRAINT FK_agt_group_teacher');
        $this->addSql('DROP TABLE activity_group_teacher');

        $this->addSql('ALTER TABLE activity_attachment DROP CONSTRAINT FK_activity_attachment_activity');
        $this->addSql('DROP TABLE activity_attachment');

        $this->addSql('ALTER TABLE activity DROP CONSTRAINT FK_activity_absence');
        $this->addSql('ALTER TABLE activity DROP CONSTRAINT FK_activity_time_slot');
        $this->addSql('DROP TABLE activity');

        $this->addSql('ALTER TABLE absence DROP CONSTRAINT FK_absence_teacher');
        $this->addSql('ALTER TABLE absence DROP CONSTRAINT FK_absence_year');
        $this->addSql('DROP TABLE absence');
    }
}
