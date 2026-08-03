<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade el ajuste de aviso por correo "Nota que implica registro de parte" (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, category, category_order, position, choices) VALUES
            ('00000000-0000-4000-8000-000000000031', 'notifications.email_daily_note_threshold', 'choice', 'none', 1, 1, 0, NULL, NULL, 'settings.category.email_alerts', 50, 130, 'none,group_tutor,admin,both')
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key = 'notifications.email_daily_note_threshold'");
    }
}
