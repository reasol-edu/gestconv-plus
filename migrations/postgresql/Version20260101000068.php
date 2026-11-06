<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000068 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incidencias: persona asignada (issue.assignee_id) y el aviso notifications.receive_issue_assigned (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql('ALTER TABLE issue ADD assignee_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_issue_assignee ON issue (assignee_id)');
        $this->addSql('ALTER TABLE issue ADD CONSTRAINT fk_issue_assignee FOREIGN KEY (assignee_id) REFERENCES teacher (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(
            "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            (gen_random_uuid(), 'notifications.receive_issue_assigned', 'boolean', 'true', TRUE, TRUE, TRUE, NULL, NULL, 'settings.category.email_receive_issues', 56, 70)"
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        foreach (['global_setting_value', 'centre_setting_value', 'teacher_setting_value'] as $table) {
            $this->addSql("DELETE FROM $table WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'notifications.receive_issue_assigned')");
        }
        $this->addSql("DELETE FROM setting_definition WHERE key = 'notifications.receive_issue_assigned'");

        $this->addSql('ALTER TABLE issue DROP CONSTRAINT fk_issue_assignee');
        $this->addSql('DROP INDEX idx_issue_assignee');
        $this->addSql('ALTER TABLE issue DROP assignee_id');
    }
}
