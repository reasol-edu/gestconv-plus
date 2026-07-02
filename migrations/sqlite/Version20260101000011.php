<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de notificaciones: quién notifica partes y sanciones (SQLite)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices) VALUES
            ('00000000-0000-4000-8000-000000000003', 'notifications.report_notifier',   'choice', 'both', 1, 1, 0, NULL, NULL, 'report_teacher,group_tutor,both'),
            ('00000000-0000-4000-8000-000000000004', 'notifications.sanction_notifier', 'choice', 'both', 1, 1, 0, NULL, NULL, 'report_teacher,group_tutor,both')
        ");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof SqlitePlatform,
            'Esta migración sólo puede ejecutarse en SQLite.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.report_notifier', 'notifications.sanction_notifier')");
    }
}
