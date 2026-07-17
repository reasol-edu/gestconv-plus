<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000030 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade id propio y materia (subject) a group_teacher, crea time_slot / time_slot_teacher para los tramos horarios, y añade los ajustes de encabezado del informe de profesorado de guardia (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('PRAGMA foreign_keys = OFF');

        $uuid = "lower(hex(randomblob(4)) || '-' || hex(randomblob(2)) || '-4' || substr(hex(randomblob(2)),2)"
            . " || '-' || substr('89ab', abs(random()) % 4 + 1, 1) || substr(hex(randomblob(2)),2) || '-' || hex(randomblob(6)))";

        $this->addSql(<<<'SQL'
            CREATE TABLE __group_teacher_new (
                id         CHAR(36)     NOT NULL,
                group_id   CHAR(36)     NOT NULL,
                teacher_id CHAR(36)     NOT NULL,
                subject    VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                CONSTRAINT FK_gt_group   FOREIGN KEY (group_id)   REFERENCES "group"(id) ON DELETE CASCADE,
                CONSTRAINT FK_gt_teacher FOREIGN KEY (teacher_id) REFERENCES teacher(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('INSERT INTO __group_teacher_new (id, group_id, teacher_id) SELECT ' . $uuid . ', group_id, teacher_id FROM group_teacher');
        $this->addSql('DROP TABLE group_teacher');
        $this->addSql('ALTER TABLE __group_teacher_new RENAME TO group_teacher');

        $this->addSql('CREATE INDEX IDX_gt_group   ON group_teacher (group_id)');
        $this->addSql('CREATE INDEX IDX_gt_teacher ON group_teacher (teacher_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_gt_group_teacher_subject ON group_teacher (group_id, teacher_id, subject)');

        $this->addSql(<<<'SQL'
            CREATE TABLE time_slot (
                id                CHAR(36)     NOT NULL,
                academic_year_id  CHAR(36)     NOT NULL,
                name              VARCHAR(255) NOT NULL,
                day_of_week       SMALLINT     NOT NULL,
                start_time        TIME         NOT NULL,
                end_time          TIME         NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT FK_ts_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_ts_year ON time_slot (academic_year_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE time_slot_teacher (
                time_slot_id CHAR(36) NOT NULL,
                teacher_id   CHAR(36) NOT NULL,
                PRIMARY KEY(time_slot_id, teacher_id),
                CONSTRAINT FK_tst_time_slot FOREIGN KEY (time_slot_id) REFERENCES time_slot(id) ON DELETE CASCADE,
                CONSTRAINT FK_tst_teacher   FOREIGN KEY (teacher_id)   REFERENCES teacher(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_tst_time_slot ON time_slot_teacher (time_slot_id)');
        $this->addSql('CREATE INDEX IDX_tst_teacher   ON time_slot_teacher (teacher_id)');

        $this->addSql('PRAGMA foreign_keys = ON');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-000000000023', 'reports.guard_duty_header_left',   'richtext', '<p><strong>{title}</strong></p>', 1, 1, 0, 0, 5000, 'settings.category.reports', 60, 130),
            ('00000000-0000-4000-8000-000000000024', 'reports.guard_duty_header_right',  'richtext', '<p>{centre_name}</p>',            1, 1, 0, 0, 5000, 'settings.category.reports', 60, 140),
            ('00000000-0000-4000-8000-000000000025', 'reports.guard_duty_header_margin', 'integer',  '22',                              1, 1, 0, 10, 80,  'settings.category.reports', 60, 150)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('reports.guard_duty_header_left', 'reports.guard_duty_header_right', 'reports.guard_duty_header_margin')");

        $this->addSql('PRAGMA foreign_keys = OFF');

        $this->addSql('DROP TABLE time_slot_teacher');
        $this->addSql('DROP TABLE time_slot');

        $this->addSql(<<<'SQL'
            CREATE TABLE __group_teacher_old (
                group_id   CHAR(36) NOT NULL,
                teacher_id CHAR(36) NOT NULL,
                PRIMARY KEY(group_id, teacher_id),
                CONSTRAINT FK_gt_group   FOREIGN KEY (group_id)   REFERENCES "group"(id) ON DELETE CASCADE,
                CONSTRAINT FK_gt_teacher FOREIGN KEY (teacher_id) REFERENCES teacher(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('INSERT INTO __group_teacher_old (group_id, teacher_id) SELECT DISTINCT group_id, teacher_id FROM group_teacher');
        $this->addSql('DROP TABLE group_teacher');
        $this->addSql('ALTER TABLE __group_teacher_old RENAME TO group_teacher');

        $this->addSql('CREATE INDEX IDX_gt_group   ON group_teacher (group_id)');
        $this->addSql('CREATE INDEX IDX_gt_teacher ON group_teacher (teacher_id)');

        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
