<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes del modo tablón: duración de semana actual y siguiente (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value) VALUES
            ('00000000-0000-4000-8000-000000000006', 'board.current_week_seconds', 'integer', '15', 1, 1, 0, 0, 3600),
            ('00000000-0000-4000-8000-000000000007', 'board.next_week_seconds',    'integer', '5',  1, 1, 0, 0, 3600)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('board.current_week_seconds', 'board.next_week_seconds')");
    }
}
