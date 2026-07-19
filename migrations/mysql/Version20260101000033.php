<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea sanction_task y sanction_task_attachment para el seguimiento del trabajo escolar durante una sanción, y añade los ajustes de retención de adjuntos y avisos por correo asociados (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_task (
                id                BINARY(16)  NOT NULL,
                sanction_id       BINARY(16)  NOT NULL,
                group_teacher_id  BINARY(16)  NOT NULL,
                description       LONGTEXT    DEFAULT NULL,
                not_applicable    TINYINT(1)  NOT NULL DEFAULT 0,
                completed_at      DATETIME    DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_sanction_task_sanction      ON sanction_task (sanction_id)');
        $this->addSql('CREATE INDEX IDX_sanction_task_group_teacher ON sanction_task (group_teacher_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_sanction_task_group_teacher ON sanction_task (sanction_id, group_teacher_id)');
        $this->addSql('ALTER TABLE sanction_task ADD CONSTRAINT FK_sanction_task_sanction      FOREIGN KEY (sanction_id)      REFERENCES sanction(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sanction_task ADD CONSTRAINT FK_sanction_task_group_teacher FOREIGN KEY (group_teacher_id) REFERENCES group_teacher(id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE sanction_task_attachment (
                id           BINARY(16)    NOT NULL,
                task_id      BINARY(16)    NOT NULL,
                filename     VARCHAR(255)  NOT NULL,
                mime_type    VARCHAR(100)  NOT NULL,
                size         INT           NOT NULL,
                content      LONGBLOB      NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $this->addSql('CREATE INDEX IDX_sanction_task_attachment_task ON sanction_task_attachment (task_id)');
        $this->addSql('ALTER TABLE sanction_task_attachment ADD CONSTRAINT FK_sanction_task_attachment_task FOREIGN KEY (task_id) REFERENCES sanction_task(id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'sanction_tasks.attachment_retention_days', 'integer', '30',   1, 1, 0, 0, 3650, 'settings.category.sanction_tasks', 80, 10),
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.email_sanction_task_assigned', 'boolean', 'true', 1, 1, 0, NULL, NULL, 'settings.category.email_alerts', 50, 110),
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.sanction_task_reminder_days',   'integer', '3',    1, 1, 0, 0, 365,  'settings.category.email_alerts', 50, 120)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('sanction_tasks.attachment_retention_days', 'notifications.email_sanction_task_assigned', 'notifications.sanction_task_reminder_days')");

        $this->addSql('ALTER TABLE sanction_task_attachment DROP FOREIGN KEY FK_sanction_task_attachment_task');
        $this->addSql('DROP TABLE sanction_task_attachment');

        $this->addSql('ALTER TABLE sanction_task DROP FOREIGN KEY FK_sanction_task_sanction');
        $this->addSql('ALTER TABLE sanction_task DROP FOREIGN KEY FK_sanction_task_group_teacher');
        $this->addSql('DROP TABLE sanction_task');
    }
}
