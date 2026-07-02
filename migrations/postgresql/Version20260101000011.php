<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de notificaciones: quién notifica partes y sanciones (PostgreSQL)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, key, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices) VALUES
                (gen_random_uuid(), 'notifications.report_notifier',   'choice', 'both', TRUE, TRUE, FALSE, NULL, NULL, 'report_teacher,group_tutor,both'),
                (gen_random_uuid(), 'notifications.sanction_notifier', 'choice', 'both', TRUE, TRUE, FALSE, NULL, NULL, 'report_teacher,group_tutor,both')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Esta migración sólo puede ejecutarse en PostgreSQL.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE key IN ('notifications.report_notifier', 'notifications.sanction_notifier')");
    }
}
