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
        return 'Añade id propio y materia (subject) a group_teacher, permitiendo varias asignaturas por docente y grupo (SQLite)';
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

        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('PRAGMA foreign_keys = OFF');

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
