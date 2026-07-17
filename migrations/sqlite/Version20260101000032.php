<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000032 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade el ajuste de duración de la pantalla "Hoy" del modo tablón y ajusta las duraciones por defecto de semana actual y siguiente (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position) VALUES
            ('00000000-0000-4000-8000-000000000027', 'board.today_seconds', 'integer', '60', 1, 1, 0, 0, 3600, 'settings.category.board', 30, 5)
        ");

        $this->addSql("UPDATE setting_definition SET default_value = '10' WHERE key = 'board.current_week_seconds'");
        $this->addSql("UPDATE setting_definition SET default_value = '0'  WHERE key = 'board.next_week_seconds'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("UPDATE setting_definition SET default_value = '15' WHERE key = 'board.current_week_seconds'");
        $this->addSql("UPDATE setting_definition SET default_value = '5'  WHERE key = 'board.next_week_seconds'");

        $this->addSql("DELETE FROM setting_definition WHERE key = 'board.today_seconds'");
    }
}
