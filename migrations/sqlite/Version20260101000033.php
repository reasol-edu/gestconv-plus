<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea sanction_task y sanction_task_attachment para el seguimiento del trabajo escolar durante una sanción, y añade los ajustes de retención de adjuntos y avisos por correo asociados (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_task (
                id                CHAR(36)  NOT NULL,
                sanction_id       CHAR(36)  NOT NULL,
                group_teacher_id  CHAR(36)  NOT NULL,
                description       CLOB      DEFAULT NULL,
                not_applicable    BOOLEAN   NOT NULL DEFAULT 0,
                completed_at      DATETIME  DEFAULT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_sanction_task_sanction      FOREIGN KEY (sanction_id)      REFERENCES sanction(id) ON DELETE CASCADE,
                CONSTRAINT FK_sanction_task_group_teacher FOREIGN KEY (group_teacher_id) REFERENCES group_teacher(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_sanction_task_sanction      ON sanction_task (sanction_id)');
        $this->addSql('CREATE INDEX IDX_sanction_task_group_teacher ON sanction_task (group_teacher_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_sanction_task_group_teacher ON sanction_task (sanction_id, group_teacher_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_task_attachment (
                id           CHAR(36)      NOT NULL,
                task_id      CHAR(36)      NOT NULL,
                filename     VARCHAR(255)  NOT NULL,
                mime_type    VARCHAR(100)  NOT NULL,
                size         INTEGER       NOT NULL,
                content      BLOB          NOT NULL,
                PRIMARY KEY(id),
                CONSTRAINT FK_sanction_task_attachment_task FOREIGN KEY (task_id) REFERENCES sanction_task(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_sanction_task_attachment_task ON sanction_task_attachment (task_id)');

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-000000000028', 'sanction_tasks.attachment_retention_days', 'integer', '30', 1, 1, 0, 0, 3650, 'settings.category.sanction_tasks', 80, 10),
            ('00000000-0000-4000-8000-000000000029', 'notifications.email_sanction_task_assigned', 'boolean', 'true', 1, 1, 0, NULL, NULL, 'settings.category.email_alerts', 50, 110),
            ('00000000-0000-4000-8000-000000000030', 'notifications.sanction_task_reminder_days', 'integer', '3', 1, 1, 0, 0, 365, 'settings.category.email_alerts', 50, 120)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('sanction_tasks.attachment_retention_days', 'notifications.email_sanction_task_assigned', 'notifications.sanction_task_reminder_days')");

        $this->addSql('DROP TABLE sanction_task_attachment');
        $this->addSql('DROP TABLE sanction_task');
    }
}
