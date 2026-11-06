<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000068 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incidencias: persona asignada (issue.assignee_id) y el aviso notifications.receive_issue_assigned (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql('ALTER TABLE issue ADD COLUMN assignee_id CHAR(36) DEFAULT NULL REFERENCES teacher (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_issue_assignee ON issue (assignee_id)');

        // El id va como parámetro con los 16 bytes del UUID, que es como los envía Doctrine en SQLite (ver la migración 050).
        $this->addSql(
            "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            (?, 'notifications.receive_issue_assigned', 'boolean', 'true', 1, 1, 1, NULL, NULL, NULL, 'settings.category.email_receive_issues', 56, 70)",
            [Uuid::v4()->toBinary()]
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM $table WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'notifications.receive_issue_assigned')");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key = 'notifications.receive_issue_assigned'");

        $this->addSql('DROP INDEX idx_issue_assignee');
        $this->addSql('ALTER TABLE issue DROP COLUMN assignee_id');
    }
}
