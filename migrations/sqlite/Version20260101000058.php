<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260101000058 extends AbstractMigration
{
    private const string DAYS_AHEAD_ID = '4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f06';
    private const string NEXT_WEEK_ID  = '4f1c7a52-8d3e-4b6a-9c10-2e5a7b9d3f07';

    public function getDescription(): string
    {
        return 'Tablón: «semana actual» pasa a ser board.upcoming_seconds (Próximos días), se retira board.next_week_seconds y se añade board.events_days_ahead (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        // Los valores ya guardados cuelgan de la definición, así que renombrarla conserva la duración elegida.
        $this->addSql("UPDATE setting_definition SET key = 'board.upcoming_seconds' WHERE key = 'board.current_week_seconds'");

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'board.next_week_seconds')");
        $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'board.next_week_seconds')");
        $this->addSql("DELETE FROM setting_definition WHERE key = 'board.next_week_seconds'");

        // El id va como parámetro con los 16 bytes del UUID, que es como lo envía Doctrine en SQLite (ver la migración 050).
        $this->addSql(
            "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            (?, 'board.events_days_ahead', 'integer', '15', 1, 1, 0, 0, 90, NULL, 'settings.category.board', 30, 20)",
            [Uuid::fromString(self::DAYS_AHEAD_ID)->toBinary()]
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM global_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'board.events_days_ahead')");
        $this->addSql("DELETE FROM centre_setting_value WHERE definition_id IN (SELECT id FROM setting_definition WHERE key = 'board.events_days_ahead')");
        $this->addSql("DELETE FROM setting_definition WHERE key = 'board.events_days_ahead'");

        $this->addSql(
            "INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices, category, category_order, position) VALUES
            (?, 'board.next_week_seconds', 'integer', '0', 1, 1, 0, 0, 3600, NULL, 'settings.category.board', 30, 20)",
            [Uuid::fromString(self::NEXT_WEEK_ID)->toBinary()]
        );
        $this->addSql("UPDATE setting_definition SET key = 'board.current_week_seconds' WHERE key = 'board.upcoming_seconds'");
    }
}
