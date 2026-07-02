<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101000011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajustes de notificaciones: quién notifica partes y sanciones (MySQL / MariaDB)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql(<<<'SQL'
            INSERT INTO setting_definition (id, `key`, type, default_value, global_scope, centre_scope, teacher_scope, min_value, max_value, choices) VALUES
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.report_notifier',   'choice', 'both', 1, 1, 0, NULL, NULL, 'report_teacher,group_tutor,both'),
                (UNHEX(REPLACE(UUID(), '-', '')), 'notifications.sanction_notifier', 'choice', 'both', 1, 1, 0, NULL, NULL, 'report_teacher,group_tutor,both')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Esta migración sólo puede ejecutarse en MySQL o MariaDB.'
        );

        $this->addSql("DELETE FROM setting_definition WHERE `key` IN ('notifications.report_notifier', 'notifications.sanction_notifier')");
    }
}
