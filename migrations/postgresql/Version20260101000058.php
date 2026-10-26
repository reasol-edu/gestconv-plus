<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000058 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tablón: «semana actual» pasa a ser board.upcoming_seconds (Próximos días), se retira board.next_week_seconds y se añade board.events_days_ahead (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        // Los valores ya guardados cuelgan de la definición, así que renombrarla conserva la duración elegida.
        $this->addSql("UPDATE setting_definition SET key = 'board.upcoming_seconds' WHERE key = 'board.current_week_seconds'");

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'board.next_week_seconds')");
        $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'board.next_week_seconds')");
        $this->addSql("DELETE FROM setting_definition WHERE key = 'board.next_week_seconds'");

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (gen_random_uuid(), 'board.events_days_ahead', 'integer', '15', TRUE, TRUE, FALSE, 0, 90, NULL, 'settings.category.board', 30, 20)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'board.events_days_ahead')");
        $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'board.events_days_ahead')");
        $this->addSql("DELETE FROM setting_definition WHERE key = 'board.events_days_ahead'");

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
                (gen_random_uuid(), 'board.next_week_seconds', 'integer', '0', TRUE, TRUE, FALSE, 0, 3600, NULL, 'settings.category.board', 30, 20)
        SQL);
        $this->addSql("UPDATE setting_definition SET key = 'board.current_week_seconds' WHERE key = 'board.upcoming_seconds'");
    }
}
